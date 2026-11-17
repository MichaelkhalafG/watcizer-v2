<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ProductIndexer;
use App\Domain\Catalog\ProductPatcher;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Content\QualityGate;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PRODUCT DESCRIPTIONS BY STABLE KEY (release 2026-11-18, part B3: the 57 in-stock products of the "later" list).
 *
 *   php artisan catalog:load-descriptions <file.jsonl> [--apply | --undo]
 *
 * The file (core/database/seeders/content/catalogue/) never names a product id: ids differ between databases (the
 * Brand Fashion import's ids sit 47 to 52 lower on a scratch copy than on production). Each line names the product by
 * its WhatsApp code (`key.wa_code`, unique), cross-checked against `key.import_ref` when the line gives one, and is
 * resolved on the database it runs on. A TITLE GUARD follows: the product found must still be that `brand` and its
 * English title must still contain that `model`; otherwise the line is skipped (STOP-ROW) and nothing is written for it.
 *
 * Per language, the text replaces the current long description only while that description is still the one the file
 * was written against (`was.<locale>`, the sha1 of the text then; null = it was empty or thin): a text the team changed
 * since stays theirs. Each pair is checked by the quality gate's product profile against the record and is written only
 * when BOTH languages pass. Dry run unless --apply.
 *
 * BACKUP first (--apply): the old text of every locale replaced, the product's updated_at and its search rows, into
 * bk_rel_20261118_descriptions, once per product and field. --undo puts every one back.
 */
class CatalogLoadDescriptionsCommand extends Command
{
    protected $signature = 'catalog:load-descriptions {file : JSON Lines under database/seeders/content/catalogue/, or a path}
        {--apply : write (default: a dry run)}
        {--undo : put back everything a run replaced}';

    protected $description = 'Write product descriptions keyed by WhatsApp code, behind a title guard and the quality gate (dry run unless --apply)';

    public const BACKUP = 'bk_rel_20261118_descriptions';

    /** The backup table's CREATE, public so a test can make it on another connection first (Tests\Support\SideDdl). */
    public static function backupDdl(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::BACKUP.' (product_id BIGINT UNSIGNED NOT NULL, wa_code VARCHAR(64) NULL,'
            .' field VARCHAR(24) NOT NULL, old_value MEDIUMTEXT NULL, new_value MEDIUMTEXT NULL, backed_up_at TIMESTAMP NULL,'
            .' PRIMARY KEY (product_id, field))';
    }

    public function handle(ProductPatcher $patcher, ProductWriter $writer, ProductIndexer $indexer, QualityGate $gate): int
    {
        if ((bool) $this->option('undo')) {
            return $this->undo($indexer, $writer);
        }
        $apply = (bool) $this->option('apply');
        $lines = $this->read(Coerce::str($this->argument('file')));
        if ($lines === null) {
            return self::FAILURE;
        }
        if ($apply && ! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            DB::statement(self::backupDdl());
        }
        $thin = Coerce::int(config('catalog.thin_description_chars', 300));
        $counts = ['written' => 0, 'would write' => 0, 'held' => 0, 'stop-row' => 0, 'kept (team text)' => 0];
        foreach ($lines as $n => $d) {
            $found = $this->resolve($d);
            if (is_string($found)) {
                $counts['stop-row']++;
                $this->line(sprintf('  STOP-ROW line %d (%s): %s', $n + 1, $d['wa_code'], $found));

                continue;
            }
            $id = $found;
            $sf = Coerce::int(DB::table('storefront_product')->where('product_id', $id)->where('is_visible', 1)->orderBy('storefront_id')->value('storefront_id'));
            if ($sf === 0) {
                $counts['stop-row']++;
                $this->line(sprintf('  STOP-ROW line %d (%s): on no shop', $n + 1, $d['wa_code']));

                continue;
            }
            $r = $gate->checkProduct($sf, $id, ['description' => $d['en']], ['description' => $d['ar']]);
            if (! $r['en']['passed'] || ! $r['ar']['passed']) {
                $counts['held']++;
                $this->line(sprintf('  HELD %s: %s', $d['wa_code'], implode('; ', array_unique([...$r['en']['reasons'], ...$r['ar']['reasons']]))));

                continue;
            }
            $now = DB::table('catalog_product_translations')->where('product_id', $id)->get(['locale', 'short_description', 'long_description'])->keyBy('locale');
            $changes = [];
            foreach (['en', 'ar'] as $locale) {
                $t = $now->get($locale);
                $current = $t === null ? null : Row::nstr($t, 'long_description');
                $len = $t === null ? 0 : mb_strlen(trim(strip_tags(Coerce::str($t->short_description ?? '').' '.($current ?? ''))));
                $was = $d['was'][$locale] ?? null;
                $same = $was === null ? $len < $thin : sha1($current ?? '') === $was;
                if (! $same) {
                    $counts['kept (team text)']++;
                    $this->line($was === null
                        ? sprintf('  kept %s %s: it already has a description of %d characters (not thin)', $d['wa_code'], $locale, $len)
                        : sprintf('  kept %s %s: the text changed since the file was made', $d['wa_code'], $locale));

                    continue;
                }
                $changes['long_description.'.$locale] = $d[$locale];
            }
            if ($changes === []) {
                continue;
            }
            if (! $apply) {
                $counts['would write']++;

                continue;
            }
            $this->backup($id, $d['wa_code'], $now, array_keys($changes));
            $patcher->patch($id, $changes);
            $writer->flush($id);
            $counts['written']++;
        }
        foreach ($counts as $k => $v) {
            $this->line(sprintf('  %-18s %d', $k, $v));
        }
        $this->info($apply ? 'Written; --undo puts everything back.' : 'DRY RUN — nothing written. Re-run with --apply.');

        return $counts['stop-row'] > 0 && $apply ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The product id for a line, or why it cannot be trusted.
     *
     * @param  array{wa_code: string, import_ref: ?string, brand: string, model: string, en: string, ar: string, was: array<string, ?string>}  $d
     */
    private function resolve(array $d): int|string
    {
        $rows = DB::table('catalog_products as p')->leftJoin('catalog_brands as b', 'b.id', '=', 'p.brand_id')
            ->where('p.wa_code', $d['wa_code'])->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->get(['p.id', 'p.import_ref', 'b.slug']);
        if ($rows->count() !== 1) {
            return $rows->count().' active products carry this code (exactly one must)';
        }
        $p = Row::cast($rows->first() ?? (object) []);
        if ($d['import_ref'] !== null && Row::nstr($p, 'import_ref') !== $d['import_ref']) {
            return 'its import key is not the one the file was made for';
        }
        if (Row::nstr($p, 'slug') !== $d['brand']) {
            return 'its brand is no longer '.$d['brand'];
        }
        $title = Coerce::str(DB::table('catalog_product_translations')->where('product_id', Row::int($p, 'id'))->where('locale', 'en')->value('title'));
        if (! str_contains(mb_strtolower($title), mb_strtolower($d['model']))) {
            return 'its English title no longer names '.$d['model'];
        }

        return Row::int($p, 'id');
    }

    /**
     * @param  \Illuminate\Support\Collection<array-key, \stdClass>  $now
     * @param  list<string>  $fields
     */
    private function backup(int $id, string $wa, $now, array $fields): void
    {
        foreach ($fields as $field) {
            $locale = substr($field, strlen('long_description.'));
            $t = $now->get($locale);
            DB::table(self::BACKUP)->insertOrIgnore(['product_id' => $id, 'wa_code' => $wa, 'field' => 'description.'.$locale,
                'old_value' => $t === null ? null : Row::nstr($t, 'long_description'), 'new_value' => null, 'backed_up_at' => now()]);
        }
        DB::table(self::BACKUP)->insertOrIgnore(['product_id' => $id, 'wa_code' => $wa, 'field' => '_updated_at',
            'old_value' => Coerce::nstr(DB::table('catalog_products')->where('id', $id)->value('updated_at')), 'new_value' => null, 'backed_up_at' => now()]);
        foreach (DB::table('catalog_product_search')->where('product_id', $id)->get(['locale', 'body']) as $s) {
            $s = Row::cast($s);
            DB::table(self::BACKUP)->insertOrIgnore(['product_id' => $id, 'wa_code' => $wa, 'field' => '_search.'.Row::str($s, 'locale'),
                'old_value' => Row::nstr($s, 'body'), 'new_value' => null, 'backed_up_at' => now()]);
        }
    }

    /** @return list<array{wa_code: string, import_ref: ?string, brand: string, model: string, en: string, ar: string, was: array<string, ?string>}>|null */
    private function read(string $file): ?array
    {
        $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1 ? $file : database_path('seeders/content/catalogue/'.$file);
        if (! is_file($path)) {
            $this->error("no file at {$path}");

            return null;
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $d = Coerce::arr(json_decode($line, true));
            if (array_key_exists('product_id', $d)) {
                $this->error('line '.($n + 1).' names a product id; this file is keyed by WhatsApp code only');

                return null;
            }
            $key = Coerce::arr($d['key'] ?? []);
            $wa = Coerce::str($key['wa_code'] ?? '');
            $brand = Coerce::str($d['brand'] ?? '');
            $model = Coerce::str($d['model'] ?? '');
            if ($wa === '' || $brand === '' || $model === '') {
                $this->error('line '.($n + 1).' lacks key.wa_code, brand or model');

                return null;
            }
            $was = [];
            foreach (['en', 'ar'] as $l) {
                $was[$l] = Coerce::nstr(Coerce::arr($d['was'] ?? [])[$l] ?? null);
            }
            $out[] = ['wa_code' => $wa, 'import_ref' => Coerce::nstr($key['import_ref'] ?? null), 'brand' => $brand, 'model' => $model,
                'en' => Coerce::str($d['en'] ?? ''), 'ar' => Coerce::str($d['ar'] ?? ''), 'was' => $was];
        }

        return $out;
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
                $date = null;
                foreach ($items as $raw) {
                    $k = Row::cast($raw);
                    $field = Row::str($k, 'field');
                    $old = Row::nstr($k, 'old_value');
                    if ($field === '_updated_at') {
                        $date = $old;
                    } elseif (str_starts_with($field, '_search.')) {
                        $search[substr($field, 8)] = $old;
                    } elseif (str_starts_with($field, 'description.')) {
                        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', substr($field, 12))->update(['long_description' => $old]);
                    }
                }
                DB::table('catalog_products')->where('id', $id)->update(['updated_at' => $date]);
            });
            $indexer->reindex($id);
            foreach ($search as $locale => $body) {
                DB::table('catalog_product_search')->where('product_id', $id)->where('locale', $locale)->update(['body' => $body]);
            }
            $writer->flush($id);
        }
        $this->info($rows->count().' product(s) put back from '.self::BACKUP.'; the table stays until the deploy is closed.');

        return self::SUCCESS;
    }
}
