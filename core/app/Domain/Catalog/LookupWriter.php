<?php

namespace App\Domain\Catalog;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Support\ManageText;
use App\Support\Sql;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * CRUD for the twelve lookup lists the product form consumes — brands, colours, sizes, materials,
 * shapes, movements, closures, displays, units, genders, features, grades (scope item 6).
 *
 * ONE class and one screen for all of them, driven by `config('catalog.lookups')`, because they
 * are the same shape: a master row, an ar/en name pair, sometimes an extra column or two. Twelve
 * near-identical controllers would be twelve places for the ar-required rule to be forgotten.
 *
 * ── Usage counts, and why they are not just decoration ───────────────────────────────────────
 *
 * Every reference from a product or a variant to one of these rows is a `RESTRICT` foreign key
 * (M1: `restrictOnDelete()`). Deleting a colour that 200 products use therefore fails at the
 * database with a 1451 — a 500 page where a sentence belongs. So the screen shows the count next
 * to every row and the writer refuses first, naming the number. The FK stays as the backstop:
 * this is the message, not the mechanism.
 *
 * @phpstan-type LookupDef array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>, json_usage?: list<array{0: string, 1: string, 2: string}>}
 */
final class LookupWriter
{
    /** Extra-column types a lookup may declare. */
    public const TYPES = ['string', 'integer', 'boolean', 'hex', 'slug', 'image'];

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * Every configured list, narrowed.
     *
     * @return array<string, LookupDef>
     */
    public static function all(): array
    {
        $out = [];
        foreach (Coerce::arr(config('catalog.lookups', [])) as $key => $entry) {
            $out[$key] = self::narrow($key, Coerce::arr($entry));
        }

        return $out;
    }

    /**
     * One list, or a refusal. A request naming an unknown list is a 404, never a query built from
     * the request's own string: the table name comes from config, always.
     *
     * @return LookupDef
     */
    public static function definition(string $key): array
    {
        $all = self::all();
        if (! isset($all[$key])) {
            throw new InvalidArgumentException("Unknown lookup list [{$key}].");
        }

        return $all[$key];
    }

    /**
     * Validation rules for one list's payload.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $key, ?int $ignoreId = null): array
    {
        $def = self::definition($key);

        /*
         * Both languages, `min:2`, and unique WITHIN a language — exactly what the legacy dashboard
         * demanded of every one of these lists for years (`BrandController`, `ColorController` and
         * their nine siblings all read `required|string|min:2|max:255|unique:<x>_translations`).
         *
         * Counted before it was adopted, across all twelve lists and both locales: 0 duplicate
         * names and 0 blank English names. So this locks in what the data already satisfies rather
         * than imposing something the team would have to go and fix — which is the difference
         * between this rule and the four product descriptions, where the same audit said 7,087.
         *
         * Per LANGUAGE, not globally: a brand may legitimately read the same in Arabic and English
         * («Rolex» / "Rolex"), and it is two rows sharing one language's spelling that splits a
         * catalogue in half without anybody noticing.
         */
        $unique = fn (string $locale): object => Rule::unique($def['translations'], 'name')
            ->where(fn (Builder $query): Builder => $query->where('locale', $locale))
            ->ignore($ignoreId, $def['fk']);

        $rules = [
            'name.ar' => ['required', 'string', 'min:2', 'max:191', $unique('ar')],
            'name.en' => ['required', 'string', 'min:2', 'max:191', $unique('en')],
        ];

        foreach ($def['extra'] as $column => $field) {
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'string';
            $required = (bool) ($field['required'] ?? false);
            $base = $required ? ['required'] : ['nullable'];

            $rules['extra.'.$column] = match ($type) {
                'integer' => [...$base, 'integer', 'min:-32768', 'max:32767'],
                'boolean' => ['nullable', 'boolean'],
                'hex' => [...$base, 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'slug' => [
                    ...$base, 'string', 'max:120',
                    Rule::unique($def['master'], $column)->ignore($ignoreId),
                ],
                'image' => ['nullable', 'string', 'max:255'],
                default => [...$base, 'string', 'max:191'],
            };
        }

        return $rules;
    }

    /**
     * Create a row. Returns its id.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $key, array $data): int
    {
        // The twelve lists come from legacy until the switch, so a new row here is deleted by the
        // rebuild and anything pointed at it loses the reference (see PreSwitch).
        PreSwitch::assertMayCreate('lookup');

        $def = self::definition($key);

        return DB::transaction(function () use ($def, $data): int {
            $row = $this->extraColumns($def, $data, isCreate: true);
            $row['created_at'] = now();
            $row['updated_at'] = now();

            $id = (int) DB::table($def['master'])->insertGetId($row);
            $this->writeTranslations($def, $id, $data);
            $this->flush();

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $key, int $id, array $data): void
    {
        $def = self::definition($key);

        DB::transaction(function () use ($def, $id, $data): void {
            if (! DB::table($def['master'])->where('id', $id)->exists()) {
                throw new RuntimeException("Row {$id} does not exist in {$def['master']}.");
            }
            $row = $this->extraColumns($def, $data, isCreate: false);
            $this->assertMayDeactivate($def, $id, $row);
            $row['updated_at'] = now();

            DB::table($def['master'])->where('id', $id)->update($row);
            $this->writeTranslations($def, $id, $data);
            $this->flush();
        });
    }

    /**
     * Switching a lookup row OFF is refused while things still point at it (item 11, 2026-09-18).
     *
     * ── What the switch actually does, and why it needs a guard ──────────────────────────────
     *
     * `is_active` on a brand does NOT hide that brand's products — they keep selling. What it does
     * is quieter: `Storefront\Meta` drops the brand from the storefront's brand list and
     * `Storefront\Sitemaps` stops emitting its page. So switching Rolex off removes a brand
     * customers browse by, and drops a URL Google has indexed, while 125 products carry on as if
     * nothing happened. Nothing on the screen said so, and nothing went wrong loudly enough to be
     * noticed.
     *
     * The developer's rule for item 11 is "any brand with products should be active". This is that
     * rule written where it can actually hold, rather than a one-off UPDATE that is true until the
     * next person clicks the switch. It mirrors the delete refusal exactly — same shape, same
     * count, same reason — because it is the same argument: a row the catalogue still points at is
     * not one you retire by accident.
     *
     * It refuses only the TRANSITION, so a row that is already off stays editable, and it applies
     * to any list that declares a boolean named `is_active`, not to brands by name.
     *
     * @param  LookupDef  $def
     * @param  array<string, mixed>  $row  the columns about to be written
     *
     * @throws ValidationException
     */
    private function assertMayDeactivate(array $def, int $id, array $row): void
    {
        if (($def['extra']['is_active']['type'] ?? null) !== 'boolean') {
            return;
        }
        if (! array_key_exists('is_active', $row) || (bool) $row['is_active'] === true) {
            return;
        }
        // Already off: this save is about something else, and refusing it would trap the row.
        $current = DB::table($def['master'])->where('id', $id)->value('is_active');
        if ((bool) $current === false) {
            return;
        }

        $uses = $this->usageCount($def, $id);
        if ($uses === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'extra.is_active' => ManageText::t(
                'lookups.deactivate_refused',
                'لا يمكن إيقاف هذا العنصر: :count منتج يستخدمه. إيقافه يُخفيه من قوائم التصفّح ومن خريطة الموقع بينما تظل منتجاته معروضة للبيع. انقل المنتجات إلى عنصر آخر أولًا.',
                ['count' => $uses],
            ),
        ]);
    }

    /**
     * Delete a row — refused while anything references it.
     *
     * @return array{deleted: bool, reason: string}
     */
    public function delete(string $key, int $id): array
    {
        $def = self::definition($key);
        $uses = $this->usageCount($def, $id);

        if ($uses > 0) {
            return [
                'deleted' => false,
                'reason' => ManageText::t(
                    'lookups.delete_refused',
                    'لا يمكن الحذف: العنصر مستخدم في :count سجل. غيّر تلك السجلات أولًا.',
                    ['count' => $uses],
                ),
            ];
        }

        DB::transaction(function () use ($def, $id): void {
            DB::table($def['translations'])->where($def['fk'], $id)->delete();
            DB::table($def['master'])->where('id', $id)->delete();
            $this->flush();
        });

        return ['deleted' => true, 'reason' => ManageText::t('lookups.deleted', 'تم الحذف.')];
    }

    /**
     * How many rows reference this lookup row, across every declared (table, column) pair.
     *
     * @param  LookupDef  $def
     * @param  LookupDef  $def
     */
    public function usageCount(array $def, int $id): int
    {
        $total = 0;
        foreach ($def['usage'] as [$table, $column]) {
            $total += DB::table($table)->where($column, $id)->count();
        }

        /*
         * …and the same lookup stored inside a JSON spec column (wave 4D: `material_id` on a bag,
         * a wallet, a fashion product). A reference the guard cannot see is a row it will happily
         * delete out from under 166 handbags, so the JSON shape is declared and counted too.
         *
         * `JSON_EXTRACT` with a bound path: the key comes from config and the id is a binding, so
         * nothing caller-supplied becomes SQL text.
         */
        foreach ($def['json_usage'] ?? [] as [$table, $column, $key]) {
            $total += DB::table($table)
                ->whereNotNull($column)
                ->whereRaw(Sql::jsonExtract($column), ['$.'.$key, $id])
                ->count();
        }

        return $total;
    }

    /**
     * Declared JSON references, narrowed: `[table, column, key]` triples and nothing else.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function jsonUsage(mixed $declared): array
    {
        $out = [];
        foreach (Coerce::arr($declared) as $entry) {
            $pair = array_values(Coerce::arr($entry));
            $table = Coerce::nstr($pair[0] ?? null);
            $column = Coerce::nstr($pair[1] ?? null);
            $key = Coerce::nstr($pair[2] ?? null);
            if ($table !== null && $column !== null && $key !== null) {
                $out[] = [$table, $column, $key];
            }
        }

        return $out;
    }

    /**
     * One list's rows for the screen: id, both names, the extra columns, and the usage count.
     *
     * The usage count is one grouped query PER declared pair rather than one per row — a list of
     * 300 colours would otherwise issue 300 counts.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $key): array
    {
        $def = self::definition($key);

        $usage = [];
        foreach ($def['usage'] as [$table, $column]) {
            // One grouped query per declared pair, not one count per row: a list of 300 colours
            // would otherwise issue 300 queries to render one screen.
            // The column is selected by NAME (the builder quotes it) and only the aggregate is
            // raw — and `COUNT(*) as uses` is a literal, so nothing caller-supplied becomes SQL
            // text. That keeps App\Support\Sql the one place in the app that interpolates.
            $counts = DB::table($table)
                ->select($column, DB::raw('COUNT(*) as uses'))
                ->whereNotNull($column)
                ->groupBy($column)
                ->get();
            foreach ($counts as $raw) {
                $count = Row::cast($raw);
                $id = Row::int($count, $column);
                $usage[$id] = ($usage[$id] ?? 0) + Row::int($count, 'uses');
            }
        }

        /*
         * The declared TYPE of each extra column, because a value's type is part of the contract
         * and the database does not keep it (item 11, 2026-09-18).
         */
        $extraTypes = [];
        foreach ($def['extra'] as $column => $field) {
            $extraTypes[$column] = is_string($field['type'] ?? null) ? $field['type'] : 'string';
        }

        $extraColumns = array_keys($def['extra']);
        $select = array_merge(['m.id'], array_map(fn (string $c): string => 'm.'.$c, $extraColumns));
        $select[] = 'ar.name as name_ar';
        $select[] = 'en.name as name_en';

        $rows = DB::table($def['master'].' as m')
            ->leftJoin($def['translations'].' as ar', function (JoinClause $join) use ($def): void {
                $join->on('ar.'.$def['fk'], '=', 'm.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin($def['translations'].' as en', function (JoinClause $join) use ($def): void {
                $join->on('en.'.$def['fk'], '=', 'm.id')->where('en.locale', '=', 'en');
            })
            ->orderBy('m.id')
            ->get($select);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $extra = [];
            foreach ($extraColumns as $column) {
                $value = $row->{$column} ?? null;
                $value = is_scalar($value) ? $value : null;

                /*
                 * ── A boolean must LEAVE here as a boolean ───────────────────────────────────
                 *
                 * MariaDB hands a TINYINT back as the integer 1, and this line used to pass it
                 * straight through. The lookup screen's switch renders `checked={value === true}`,
                 * and `1 === true` is false in JavaScript — so all 78 brands showed as switched
                 * OFF while all 78 rows in `catalog_brands` were switched ON. That is the whole of
                 * the "brands are all inactive" report (developer, 2026-09-17): nothing was ever
                 * wrong with the data.
                 *
                 * The screen also sends the row BACK on save, and its `extraPayload()` maps a
                 * boolean column to `value === true` — which for the incoming `1` is `false`. So a
                 * rename, a new logo or a slug correction would have written `is_active = 0` on
                 * the way out. The display bug was one edit away from being a data bug.
                 *
                 * Cast HERE rather than in the React file because `config/catalog.php` declares
                 * the type and this is the code that holds the config. A cast in the browser would
                 * have left the wrong value on the wire for the CSV export and for every screen
                 * added after it.
                 */
                $extra[$column] = $extraTypes[$column] === 'boolean' ? (bool) $value : $value;
            }
            $out[] = [
                'id' => $id,
                'name' => [
                    'ar' => Row::nstr($row, 'name_ar') ?? '',
                    'en' => Row::nstr($row, 'name_en') ?? '',
                ],
                'extra' => $extra,
                'uses' => $usage[$id] ?? 0,
            ];
        }

        return $out;
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  LookupDef  $def
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extraColumns(array $def, array $data, bool $isCreate): array
    {
        $extra = Coerce::arr($data['extra'] ?? null);
        $names = Coerce::pair($data['name'] ?? null);
        $row = [];

        foreach ($def['extra'] as $column => $field) {
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'string';
            $value = $extra[$column] ?? null;

            $row[$column] = match ($type) {
                'integer' => Coerce::nint($value) ?? ($isCreate ? Coerce::int($field['default'] ?? null) : 0),
                'boolean' => Coerce::bool($value, Coerce::bool($field['default'] ?? null)),
                // Stored upper-case, as the transform stores it (deviation D-07): CSS does not
                // care, but a mixed-case column makes two identical colours look different.
                'hex' => ($hex = Coerce::nstr($value)) === null ? null : strtoupper($hex),
                'slug' => $this->slugValue($value, $names),
                'image' => Coerce::nstr($value),
                default => Coerce::nstr($value),
            };
        }

        return $row;
    }

    /**
     * A slug column: the caller's value slugified, else derived from the EN name, else the AR name
     * (which yields '' through `LegacySlug` and so becomes a refusal rather than an empty URL).
     *
     * @param  array<string, mixed>  $names
     */
    private function slugValue(mixed $value, array $names): string
    {
        $slug = LegacySlug::make(Coerce::str($value));
        if ($slug === '') {
            $slug = LegacySlug::make(Coerce::str($names['en'] ?? null));
        }
        if ($slug === '') {
            throw new RuntimeException(ManageText::t('lookups.slug_latin_required', 'الرابط (slug) مطلوب بحروف لاتينية: الروابط العامة لا تُبنى من العربية.'));
        }

        return $slug;
    }

    /**
     * ar is required; an empty en is DELETED rather than stored blank — fallback is off
     * (AGENTS §2.17), so a blank row would show as a real empty name on the storefront.
     *
     * @param  LookupDef  $def
     * @param  array<string, mixed>  $data
     */
    private function writeTranslations(array $def, int $id, array $data): void
    {
        $names = Coerce::pair($data['name'] ?? null);

        foreach (['ar', 'en'] as $locale) {
            $name = $names[$locale];

            if ($name === '') {
                // Both languages are required since 2026-09-18, matching legacy. The English row is
                // no longer deleted when blank, because blank is no longer an allowed answer.
                throw new RuntimeException($locale === 'ar'
                    ? ManageText::t('lookups.name_ar_required', 'الاسم العربي مطلوب.')
                    : ManageText::t('lookups.name_en_required', 'الاسم الإنجليزي مطلوب.'));
            }

            DB::table($def['translations'])->updateOrInsert(
                [$def['fk'] => $id, 'locale' => $locale],
                ['name' => $name],
            );
        }
    }

    /**
     * Every lookup name is in `catalog/meta`, the compat `names` payload and every product DTO, so
     * a rename must invalidate every storefront — `LookupChanged` in the invalidation map.
     */
    private function flush(): void
    {
        foreach (DB::table('storefronts')->pluck('id') as $id) {
            if (is_numeric($id)) {
                $this->cache->flush((int) $id);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return LookupDef
     */
    private static function narrow(string $key, array $entry): array
    {
        $extra = [];
        foreach (Coerce::arr($entry['extra'] ?? null) as $column => $field) {
            $declared = Coerce::arr($field);
            $type = Coerce::str($declared['type'] ?? null, 'string');
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("config/catalog.php: lookup [{$key}] column [{$column}] has unknown type [{$type}].");
            }
            /*
             * The extra column's LABEL through the seam (🟠-4, 2026-09-17), keyed on the list and
             * the column — both stable. The config value stays as the Arabic fallback, because a
             * `ManageText::t()` inside `config/catalog.php` would be frozen into `config:cache` in
             * whatever locale happened to be active when the cache was built.
             */
            if (is_string($declared['label'] ?? null)) {
                $declared['label'] = ManageText::t('lookups.column_'.$key.'_'.$column, $declared['label']);
            }

            $extra[$column] = $declared;
        }

        $usage = [];
        foreach (Coerce::arr($entry['usage'] ?? null) as $pair) {
            // A `[table, column]` pair, read positionally — `Coerce::arr()` string-keys
            // everything, so the values are taken rather than the offsets.
            $columns = array_values(Coerce::arr($pair));
            $table = Coerce::nstr($columns[0] ?? null);
            $column = Coerce::nstr($columns[1] ?? null);
            if ($table !== null && $column !== null) {
                $usage[] = [$table, $column];
            }
        }

        return [
            'key' => $key,
            // The list's own name ("الماركات", "الألوان") — same contract as the columns above.
            'label' => ManageText::t('lookups.list_'.$key, Coerce::str($entry['label'] ?? null, $key)),
            'master' => Coerce::str($entry['master'] ?? null),
            'translations' => Coerce::str($entry['translations'] ?? null),
            'fk' => Coerce::str($entry['fk'] ?? null),
            'extra' => $extra,
            'usage' => $usage,
            // Declared JSON references, carried through as config wrote them: `usageCount()` reads
            // them with JSON_EXTRACT (wave 4D — `material_id` inside a product's `specs`).
            'json_usage' => self::jsonUsage($entry['json_usage'] ?? null),
        ];
    }
}
