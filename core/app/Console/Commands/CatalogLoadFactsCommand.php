<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ProductIndexer;
use App\Domain\Catalog\ProductPatcher;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Content\QualityGate;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * THE MISSING PRODUCT FACTS, filled from sourced data (Michael, 2026-11-15; SEO combined deploy, step 11f).
 *
 *   php artisan catalog:load-facts <facts.tsv> [--descriptions=<file.jsonl>] [--apply | --undo]
 *
 * The facts file (core/database/seeders/content/catalogue/) is made off the server from three sources, in this order of
 * trust, and names the source of every value: the product's own title, the old Brand Fashion site's export, and a
 * retailer's public spec table matched by brand AND model code — values only, never anyone's words or photos.
 * Columns: product_id, field, value, source, ref. Fields: code (→ sku), gender, case_size (mm), water_m (metres),
 * movement, band_mat (strap / bracelet material), dial_colour.
 *
 * A field is FILLED, never overwritten: a product that has the field already is skipped, so the run is idempotent and a
 * value the team set by hand always wins. A product deleted, switched off or no longer in the file's shape is skipped.
 *
 * CORRECTIONS (Michael, 2026-11-15) are rows whose field is `fix:case_size`, `fix:water_m` or `fix:movement`, with an
 * `expect` column: the value the record holds today. One replaces that value only while the record still holds exactly
 * it (a value changed since is the team's, and stays), and only where the brand's official site confirms the new value
 * or two independent sources agree — the file says which. They are backed up and undone like the fills.
 *
 * The descriptions file (JSON Lines: product_id, en, ar, replace, was) holds our own descriptions written from those
 * facts; each pair is checked by the quality gate's product profile against the record AS IT IS AFTER the facts
 * (QualityGate::checkProduct) and only a pair that passes in both languages is written — in the locales `replace` names
 * (a THIN text, under catalog.thin_description_chars, or one that names another brand), and only over the very text the
 * file was made from (`was`, its sha1): a text the team changed since stays theirs. A held pair changes nothing.
 *
 * BACKUP first (--apply): every value replaced, into bk_seo_20261115_facts, once per product and field (never
 * overwritten). --undo puts every one back, byte for byte, and removes what the run added.
 */
final class CatalogLoadFactsCommand extends Command
{
    protected $signature = 'catalog:load-facts {file : the facts TSV, relative to database/seeders/content/catalogue or absolute}
        {--descriptions= : the descriptions JSONL, same folder}
        {--apply : write (backup first)}
        {--undo : put back everything a previous --apply changed}';

    protected $description = 'Fill missing product facts (and thin descriptions) from a sourced file — dry run unless --apply';

    private const BACKUP = 'bk_seo_20261115_facts';

    private const FIELDS = ['code', 'gender', 'case_size', 'water_m', 'movement', 'band_mat', 'dial_colour', 'fix:case_size', 'fix:water_m', 'fix:movement'];

    private const UNIT_MM = 'mm';

    private const UNIT_METRE = 'metre';

    /** @var array{material: array<string, int>, movement: array<string, int>, colour: array<string, int>, gender: array<string, int>, mm: int, metre: int}|null */
    private ?array $lookupCache = null;

    public function handle(ProductPatcher $patcher, ProductIndexer $indexer, ProductWriter $writer, QualityGate $gate): int
    {
        if ($this->option('undo')) {
            return $this->undo($indexer, $writer);
        }
        $apply = (bool) $this->option('apply');
        $rows = $this->readFacts(Coerce::str($this->argument('file')));
        if ($rows === null) {
            return self::FAILURE;
        }
        $lookups = $this->lookups();

        // ---- the plan: what each row would write, checked against the record now ----------------------------------
        $plan = [];
        $skipped = [];
        foreach ($rows as $r) {
            $why = $this->planRow($r, $lookups, $plan);
            if ($why !== null) {
                $skipped[$why] = ($skipped[$why] ?? 0) + 1;
            }
        }
        $byField = [];
        foreach ($plan as $p) {
            $byField[$p['field'].' ('.$p['source'].')'] = ($byField[$p['field'].' ('.$p['source'].')'] ?? 0) + 1;
        }
        ksort($byField);
        $this->line('facts: '.count($rows).' rows; '.count($plan).' to fill');
        foreach ($byField as $k => $n) {
            $this->line(sprintf('  %-26s %d', $k, $n));
        }
        foreach ($skipped as $why => $n) {
            $this->line(sprintf('  skipped: %-40s %d', $why, $n));
        }

        $descriptions = [];
        $descFile = Coerce::str($this->option('descriptions') ?? '');
        if ($descFile !== '') {
            $descriptions = $this->readDescriptions($descFile);
            if ($descriptions === null) {
                return self::FAILURE;
            }
            $this->line('descriptions: '.count($descriptions).' in the file (checked after the facts are in place)');
        }

        if (! $apply) {
            if ($descriptions !== []) {
                // a dry run cannot see the facts in place, so it checks against the record as it is now (the deploy's
                // rehearsal runs the apply)
                $heldReasons = [];
                [$pass, $held] = $this->checkDescriptions($gate, $descriptions, $heldReasons);
                $this->line("descriptions against today's record: {$pass} would pass, {$held} held");
                foreach (array_slice($heldReasons, 0, 10) as $line) {
                    $this->line('  HELD '.$line);
                }
            }
            $this->info('DRY RUN — nothing written. Re-run with --apply.');

            return self::SUCCESS;
        }

        // ---- the backup, then the writes, one product at a time -----------------------------------------------------
        // DDL only when the table is missing (a CREATE commits whatever transaction is open)
        DB::getSchemaBuilder()->hasTable(self::BACKUP) || DB::statement('CREATE TABLE '.self::BACKUP.' (product_id BIGINT UNSIGNED NOT NULL, field VARCHAR(24) NOT NULL,'
            .' old_value LONGTEXT NULL, new_value LONGTEXT NULL, source VARCHAR(24) NULL, ref VARCHAR(255) NULL, specs_row_added TINYINT(1) NOT NULL DEFAULT 0,'
            .' backed_up_at TIMESTAMP NULL, PRIMARY KEY (product_id, field))');
        $byProduct = [];
        foreach ($plan as $p) {
            $byProduct[$p['product_id']][] = $p;
        }
        $written = 0;
        foreach ($byProduct as $id => $items) {
            $this->keepDate($id);
            DB::transaction(function () use ($id, $items, &$written): void {
                foreach ($items as $p) {
                    if ($this->write($id, $p)) {
                        $written++;
                    }
                }
            });
            ActivityLog::record('catalog_products', $id, ActivityLog::UPDATED, [],
                array_combine(array_column($items, 'field'), array_column($items, 'value')),
                mb_substr('facts filled from '.implode(', ', array_unique(array_column($items, 'source'))).' - catalog:load-facts', 0, 191));
            $indexer->reindex($id);
            $writer->flush($id);
        }
        $this->line("facts written: {$written}");

        if ($descriptions !== []) {
            $heldReasons = [];
            [$pass, $held, $wrote] = $this->checkDescriptions($gate, $descriptions, $heldReasons, $patcher, $writer);
            $this->line("descriptions: {$wrote} written ({$pass} passed the product profile; a text already replaced is left), {$held} held — the product keeps its own");
            foreach (array_slice($heldReasons, 0, 20) as $line) {
                $this->line('  HELD '.$line);
            }
        }
        $this->info('written. Backup: '.self::BACKUP.' — undo: php artisan catalog:load-facts - --undo');

        return self::SUCCESS;
    }

    /**
     * @return list<array{product_id: int, field: string, value: string, source: string, ref: string, expect: string}>|null
     */
    private function readFacts(string $file): ?array
    {
        $path = $this->path($file);
        if (! is_file($path)) {
            $this->error("no facts file at {$path}");

            return null;
        }
        $out = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $head = array_flip(explode("\t", (string) array_shift($lines)));
        foreach (['product_id', 'field', 'value', 'source', 'ref'] as $col) {
            if (! isset($head[$col])) {
                $this->error("the facts file has no {$col} column");

                return null;
            }
        }
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $c = explode("\t", $line);
            $field = $c[$head['field']] ?? '';
            if (! in_array($field, self::FIELDS, true)) {
                $this->error("unknown field [{$field}] in the facts file");

                return null;
            }
            $out[] = ['product_id' => (int) ($c[$head['product_id']] ?? 0), 'field' => $field, 'value' => trim($c[$head['value']] ?? ''),
                'source' => trim($c[$head['source']] ?? ''), 'ref' => mb_substr(trim($c[$head['ref']] ?? ''), 0, 255),
                'expect' => isset($head['expect']) ? trim($c[$head['expect']] ?? '') : ''];
            if (str_starts_with($field, 'fix:') && end($out)['expect'] === '') {
                $this->error("a correction for product {$c[$head['product_id']]} has no expect value");

                return null;
            }
        }

        return $out;
    }

    /** @return array<int, array{en: string, ar: string, replace: list<string>, was: array<string, string>}>|null */
    private function readDescriptions(string $file): ?array
    {
        $path = $this->path($file);
        if (! is_file($path)) {
            $this->error("no descriptions file at {$path}");

            return null;
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $d = Coerce::arr(json_decode($line, true));
            $replace = array_values(array_intersect(['en', 'ar'], array_map(fn ($l): string => Coerce::str($l), Coerce::arr($d['replace'] ?? []))));
            $was = [];
            foreach (Coerce::arr($d['was'] ?? []) as $l => $h) {
                $was[Coerce::str($l)] = Coerce::str($h);
            }
            $out[Coerce::int($d['product_id'] ?? 0)] = ['en' => Coerce::str($d['en'] ?? ''), 'ar' => Coerce::str($d['ar'] ?? ''), 'replace' => $replace, 'was' => $was];
        }

        return $out;
    }

    private function path(string $file): string
    {
        return str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1
            ? $file : database_path('seeders/content/catalogue/'.$file);
    }

    /** @return array{material: array<string, int>, movement: array<string, int>, colour: array<string, int>, gender: array<string, int>, mm: int, metre: int} */
    private function lookups(): array
    {
        if ($this->lookupCache !== null) {
            return $this->lookupCache;
        }
        $names = fn (string $table, string $fk): array => DB::table($table)->where('locale', 'en')->pluck($fk, 'name')
            ->mapWithKeys(fn ($id, $name): array => [mb_strtolower(Coerce::str($name)) => Coerce::int($id)])->all();

        return $this->lookupCache = [
            'material' => $names('catalog_material_translations', 'material_id'),
            'movement' => $names('catalog_movement_type_translations', 'movement_type_id'),
            'colour' => $names('catalog_color_translations', 'color_id'),
            'gender' => $names('catalog_gender_translations', 'gender_id'),
            'mm' => Coerce::int(DB::table('catalog_units')->where('code', self::UNIT_MM)->value('id')),
            'metre' => Coerce::int(DB::table('catalog_units')->where('code', self::UNIT_METRE)->value('id')),
        ];
    }

    /**
     * One row of the file → one write in the plan, or the reason it is skipped.
     *
     * @param  array{product_id: int, field: string, value: string, source: string, ref: string, expect: string}  $r
     * @param  array{material: array<string, int>, movement: array<string, int>, colour: array<string, int>, gender: array<string, int>, mm: int, metre: int}  $l
     * @param  array<string, array{product_id: int, field: string, value: string, source: string, ref: string, expect: string, target: mixed}>  $plan
     */
    private function planRow(array $r, array $l, array &$plan): ?string
    {
        $p = DB::table('catalog_products')->where('id', $r['product_id'])->first(['id', 'family', 'sku', 'is_active', 'deleted_at']);
        if ($p === null || Row::nstr($p, 'deleted_at') !== null || ! Row::bool($p, 'is_active')) {
            return 'product gone or switched off';
        }
        $id = $r['product_id'];
        $specs = DB::table('catalog_product_watch_specs')->where('product_id', $id)->first();
        $spec = fn (string $col): ?string => $specs === null ? null : Row::nstr($specs, $col);
        $empty = fn (?string $v): bool => $v === null || trim($v) === '' || (is_numeric($v) && (float) $v == 0.0);
        $watch = Row::str($p, 'family') === 'watch';
        $target = null;
        if (str_starts_with($r['field'], 'fix:')) {
            if (! $watch) {
                return 'correction: not a watch';
            }
            $now = match ($r['field']) {
                'fix:case_size' => $spec('case_size') === null ? null : sprintf('%g', (float) $spec('case_size')),
                'fix:water_m' => $this->waterMetres($spec('water_resistance'), $spec('water_resistance_unit_id')),
                default => $spec('movement_type_id') === null ? null : (string) array_search((int) $spec('movement_type_id'), $l['movement'], true),
            };
            if ($now === null || $now !== $r['expect']) {
                return 'correction: the record changed since (kept as it is)';
            }
            $target = match ($r['field']) {
                'fix:case_size' => is_numeric($r['value']) ? (float) $r['value'] : null,
                'fix:water_m' => ctype_digit($r['value']) ? (int) $r['value'] : null,
                default => $l['movement'][$r['value']] ?? null,
            };
            if ($target === null) {
                return 'correction: value not in the shop\'s lists';
            }
            $plan[$id.'|'.$r['field']] = $r + ['target' => $target];

            return null;
        }
        switch ($r['field']) {
            case 'code':
                if (! $empty(Row::nstr($p, 'sku'))) {
                    return 'code: has one already';
                }
                $target = mb_substr(strtoupper($r['value']), 0, 64);
                break;
            case 'gender':
                if (DB::table('catalog_product_gender')->where('product_id', $id)->exists()) {
                    return 'gender: has one already';
                }
                $target = $l['gender'][$r['value']] ?? null;
                break;
            case 'case_size':
                if (! $watch || ! $empty($spec('case_size'))) {
                    return 'case size: has one already, or not a watch';
                }
                $target = is_numeric($r['value']) ? (float) $r['value'] : null;
                break;
            case 'water_m':
                if (! $watch || ! $empty($spec('water_resistance'))) {
                    return 'water rating: has one already, or not a watch';
                }
                $target = ctype_digit($r['value']) ? (int) $r['value'] : null;
                break;
            case 'movement':
                if (! $watch || ! $empty($spec('movement_type_id'))) {
                    return 'movement: has one already, or not a watch';
                }
                $target = $l['movement'][$r['value']] ?? null;
                break;
            case 'band_mat':
                if (! $watch || ! $empty($spec('band_material_id'))) {
                    return 'strap material: has one already, or not a watch';
                }
                $target = $l['material'][$r['value']] ?? null;
                break;
            case 'dial_colour':
                if (! $watch || DB::table('catalog_product_color')->where('product_id', $id)->where('role', 'dial')->exists()) {
                    return 'dial colour: has one already, or not a watch';
                }
                $target = $l['colour'][$r['value'] === 'grey' ? 'gray' : $r['value']] ?? null;
                break;
        }
        if ($target === null || $target === '') {
            return $r['field'].': value not in the shop\'s lists';
        }
        $plan[$id.'|'.$r['field']] = $r + ['target' => $target];

        return null;
    }

    /** The record's water rating in metres, as the facts file writes it ('atm' and 'bar' are 10 m; 'metre' and 'm' metres). */
    private function waterMetres(?string $value, ?string $unitId): ?string
    {
        if ($value === null || (float) $value == 0.0) {
            return null;
        }
        $code = $unitId === null ? null : Coerce::str(DB::table('catalog_units')->where('id', (int) $unitId)->value('code'));

        return (string) (int) round((float) $value * (in_array($code, ['atm', 'bar'], true) ? 10 : 1));
    }

    /** @param  array{product_id: int, field: string, value: string, source: string, ref: string, expect: string, target: mixed}  $p */
    private function write(int $id, array $p): bool
    {
        $l = $this->lookups();
        $field = $p['field'];
        $column = str_starts_with($field, 'fix:') ? substr($field, 4) : $field;
        $backup = fn (?string $old, bool $rowAdded = false) => DB::table(self::BACKUP)->insertOrIgnore([
            'product_id' => $id, 'field' => $field, 'old_value' => $old, 'new_value' => (string) Coerce::str($p['target']),
            'source' => $p['source'], 'ref' => $p['ref'], 'specs_row_added' => $rowAdded ? 1 : 0, 'backed_up_at' => now(),
        ]);
        if ($field === 'code') {
            $backup(Row::nstr(DB::table('catalog_products')->where('id', $id)->first(['sku']) ?? (object) ['sku' => null], 'sku'));
            DB::table('catalog_products')->where('id', $id)->update(['sku' => $p['target'], 'updated_at' => now()]);

            return true;
        }
        if ($field === 'gender') {
            $backup(null);
            DB::table('catalog_product_gender')->insertOrIgnore(['product_id' => $id, 'gender_id' => Coerce::int($p['target'])]);

            return true;
        }
        if ($field === 'dial_colour') {
            $backup(null);
            DB::table('catalog_product_color')->insertOrIgnore(['product_id' => $id, 'color_id' => Coerce::int($p['target']), 'role' => 'dial', 'position' => 0]);

            return true;
        }
        $added = false;
        if (! DB::table('catalog_product_watch_specs')->where('product_id', $id)->exists()) {
            DB::table('catalog_product_watch_specs')->insert(['product_id' => $id]);
            $added = true;
        }
        $set = match ($column) {
            'case_size' => ['case_size' => $p['target'], 'case_size_unit_id' => $l['mm']],
            'water_m' => ['water_resistance' => $p['target'], 'water_resistance_unit_id' => $l['metre']],
            'movement' => ['movement_type_id' => $p['target']],
            'band_mat' => ['band_material_id' => $p['target']],
            default => [],
        };
        $old = DB::table('catalog_product_watch_specs')->where('product_id', $id)->first(array_keys($set));
        // once a run added the specs row, every later field of that product says so too (the undo removes the row last)
        $rowAdded = $added || DB::table(self::BACKUP)->where('product_id', $id)->where('specs_row_added', 1)->exists();
        $backup((string) json_encode($old === null ? [] : (array) $old), $rowAdded);
        DB::table('catalog_product_watch_specs')->where('product_id', $id)->update($set);

        return true;
    }

    /**
     * Check each description pair with the product profile; with a patcher, write the ones that pass over a THIN text.
     *
     * @param  array<int, array{en: string, ar: string, replace: list<string>, was: array<string, string>}>  $descriptions
     * @param  list<string>  $heldReasons
     * @return array{0: int, 1: int, 2: int} passed, held, written (a pass over a text already replaced writes nothing)
     */
    private function checkDescriptions(QualityGate $gate, array $descriptions, array &$heldReasons, ?ProductPatcher $patcher = null, ?ProductWriter $writer = null): array
    {
        $heldReasons = [];
        $thin = Coerce::int(config('catalog.thin_description_chars', 300));
        $pass = 0;
        $held = 0;
        $wrote = 0;
        foreach ($descriptions as $id => $d) {
            $sf = Coerce::int(DB::table('storefront_product')->where('product_id', $id)->where('is_visible', 1)->orderBy('storefront_id')->value('storefront_id'));
            if ($sf === 0) {
                $held++;
                $heldReasons[] = "#{$id}: not on a shop any more";

                continue;
            }
            $r = $gate->checkProduct($sf, $id, ['description' => $d['en']], ['description' => $d['ar']]);
            if (! $r['en']['passed'] || ! $r['ar']['passed']) {
                $held++;
                $heldReasons[] = "#{$id}: ".implode('; ', array_unique([...$r['en']['reasons'], ...$r['ar']['reasons']]));

                continue;
            }
            $pass++;
            if ($patcher === null || $writer === null) {
                continue;
            }
            $now = DB::table('catalog_product_translations')->where('product_id', $id)->get(['locale', 'short_description', 'long_description'])->keyBy('locale');
            $changes = [];
            foreach (['en', 'ar'] as $locale) {
                $t = $now->get($locale);
                $current = $t === null ? null : Row::nstr($t, 'long_description');
                $len = $t === null ? 0 : mb_strlen(trim(strip_tags(Coerce::str($t->short_description ?? '').' '.($current ?? ''))));
                // the file names its locales and the text it saw; without them, a thin text only
                $named = $d['replace'] === [] ? $len < $thin
                    : in_array($locale, $d['replace'], true) && sha1($current ?? '') === ($d['was'][$locale] ?? '');
                if ($named) {
                    DB::table(self::BACKUP)->insertOrIgnore(['product_id' => $id, 'field' => 'description.'.$locale,
                        'old_value' => $t === null ? null : Row::nstr($t, 'long_description'), 'new_value' => $d[$locale],
                        'source' => 'written', 'ref' => 'from the record\'s facts', 'specs_row_added' => 0, 'backed_up_at' => now()]);
                    $changes['long_description.'.$locale] = $d[$locale];
                }
            }
            if ($changes !== []) {
                $this->keepDate($id);
                $patcher->patch($id, $changes);
                $writer->flush($id);
                $wrote++;
            }
        }

        return [$pass, $held, $wrote];
    }

    /** The product's own updated_at, once, before this run first changes it — the undo puts it back last. */
    private function keepDate(int $id): void
    {
        DB::table(self::BACKUP)->insertOrIgnore(['product_id' => $id, 'field' => '_updated_at',
            'old_value' => Row::nstr(DB::table('catalog_products')->where('id', $id)->first(['updated_at']) ?? (object) ['updated_at' => null], 'updated_at'),
            'new_value' => null, 'source' => null, 'ref' => null, 'specs_row_added' => 0, 'backed_up_at' => now()]);
        // and its search rows as they are (an older indexer's rows stay exactly as they were after an undo)
        foreach (DB::table('catalog_product_search')->where('product_id', $id)->get(['locale', 'body']) as $s) {
            DB::table(self::BACKUP)->insertOrIgnore(['product_id' => $id, 'field' => '_search.'.Row::str($s, 'locale'),
                'old_value' => Row::nstr($s, 'body'), 'new_value' => null, 'source' => null, 'ref' => null, 'specs_row_added' => 0, 'backed_up_at' => now()]);
        }
    }

    private function undo(ProductIndexer $indexer, ProductWriter $writer): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->error('No backup table '.self::BACKUP.' — nothing was written here.');

            return self::FAILURE;
        }
        $rows = DB::table(self::BACKUP)->orderBy('product_id')->get()->groupBy('product_id');
        foreach ($rows as $id => $items) {
            $id = Coerce::int($id);
            $search = [];
            DB::transaction(function () use ($id, $items, &$search): void {
                $dropRow = false;
                $date = null;
                foreach ($items as $raw) {
                    $k = Row::cast($raw);
                    $field = Row::str($k, 'field');
                    $old = Row::nstr($k, 'old_value');
                    if ($field === '_updated_at') {
                        $date = $old;

                        continue;
                    }
                    if (str_starts_with($field, '_search.')) {
                        $search[substr($field, 8)] = $old;

                        continue;
                    }
                    $dropRow = $dropRow || Row::bool($k, 'specs_row_added');
                    if ($field === 'code') {
                        DB::table('catalog_products')->where('id', $id)->update(['sku' => $old]);
                    } elseif ($field === 'gender') {
                        DB::table('catalog_product_gender')->where('product_id', $id)->where('gender_id', Coerce::int(Row::str($k, 'new_value')))->delete();
                    } elseif ($field === 'dial_colour') {
                        DB::table('catalog_product_color')->where('product_id', $id)->where('role', 'dial')->where('color_id', Coerce::int(Row::str($k, 'new_value')))->delete();
                    } elseif (str_starts_with($field, 'description.')) {
                        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', substr($field, 12))->update(['long_description' => $old]);
                    } else {
                        $before = Coerce::arr(json_decode($old ?? '{}', true));
                        if ($before !== []) {
                            DB::table('catalog_product_watch_specs')->where('product_id', $id)->update($before);
                        }
                    }
                }
                if ($dropRow) {
                    DB::table('catalog_product_watch_specs')->where('product_id', $id)->delete();
                }
                DB::table('catalog_products')->where('id', $id)->update(['updated_at' => $date]);
            });
            $indexer->reindex($id);
            // the search rows exactly as they were (reindex rebuilds them with today's indexer, then they go back)
            foreach ($search as $locale => $body) {
                DB::table('catalog_product_search')->where('product_id', $id)->where('locale', $locale)->update(['body' => $body]);
            }
            $writer->flush($id);
        }
        $this->info($rows->count().' product(s) put back from '.self::BACKUP.'; the table stays until the deploy is closed.');

        return self::SUCCESS;
    }
}
