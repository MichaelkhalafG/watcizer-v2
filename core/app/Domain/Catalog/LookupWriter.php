<?php

namespace App\Domain\Catalog;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
 * @phpstan-type LookupDef array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>}
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

        $rules = [
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
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
            $row['updated_at'] = now();

            DB::table($def['master'])->where('id', $id)->update($row);
            $this->writeTranslations($def, $id, $data);
            $this->flush();
        });
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
                'reason' => "لا يمكن الحذف: العنصر مستخدم في {$uses} سجل. غيّر تلك السجلات أولًا.",
            ];
        }

        DB::transaction(function () use ($def, $id): void {
            DB::table($def['translations'])->where($def['fk'], $id)->delete();
            DB::table($def['master'])->where('id', $id)->delete();
            $this->flush();
        });

        return ['deleted' => true, 'reason' => 'تم الحذف.'];
    }

    /**
     * How many rows reference this lookup row, across every declared (table, column) pair.
     *
     * @param  LookupDef  $def
     */
    public function usageCount(array $def, int $id): int
    {
        $total = 0;
        foreach ($def['usage'] as [$table, $column]) {
            $total += DB::table($table)->where($column, $id)->count();
        }

        return $total;
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
                $extra[$column] = is_scalar($value) ? $value : null;
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
            throw new RuntimeException('الرابط (slug) مطلوب بحروف لاتينية: الروابط العامة لا تُبنى من العربية.');
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
                if ($locale === 'ar') {
                    throw new RuntimeException('الاسم العربي مطلوب.');
                }
                DB::table($def['translations'])->where($def['fk'], $id)->where('locale', $locale)->delete();

                continue;
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
            'label' => Coerce::str($entry['label'] ?? null, $key),
            'master' => Coerce::str($entry['master'] ?? null),
            'translations' => Coerce::str($entry['translations'] ?? null),
            'fk' => Coerce::str($entry['fk'] ?? null),
            'extra' => $extra,
            'usage' => $usage,
        ];
    }
}
