<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * The PARTIAL-update path for a lookup row — the second half of what §2.9.7 left owed (wave 4D).
 *
 * ── Why a lookup needs its own patcher ───────────────────────────────────────────────────────
 *
 * `PUT /manage/lookups/{list}/{id}` is the OTHER full-REPLACE endpoint, and its failure mode is
 * quieter than the product form's. `LookupWriter::extraColumns()` reads
 * `$data['extra'][$column] ?? null` for every declared column, so an omitted key is stored as
 * NULL — and for a declared `integer` column on an update it is stored as **0**, not even null.
 * That is how a colour lost its hex and `catalog/meta` began serving `color_value: null` where
 * legacy had `#111111`: one absent key in one payload, nothing wrong on any screen, caught by the
 * compat harness.
 *
 * This class changes only what it is given. Same contract as {@see ProductPatcher} — declared
 * addressable set, `_complete` refused, a diff against the stored values, never a creation — with
 * two rules that belong to lookups specifically:
 *
 *  - **the Arabic name cannot be emptied.** `LookupWriter` refuses it and so does this: a lookup
 *    with no Arabic name is a blank row in every filter and every spec block.
 *  - **a `slug` column stays unique, checked here.** The form path gets that from a validation
 *    `Rule::unique`; a patcher has no validator, so it asks the table and refuses by name. A
 *    duplicate slug is two categories fighting over one URL.
 *
 * The units-cleanup screen (4D item 3) is the first caller: merging and retiring mis-assigned
 * codes is a sequence of small edits to existing rows, which is precisely what the full-replace
 * endpoint cannot express safely.
 */
final class LookupPatcher
{
    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * Apply only what `$changes` names, on one row of one list.
     *
     * @param  array<string, mixed>  $changes  `name.ar` | `name.en` | `extra.<column>` => value
     *
     * @throws RuntimeException on an unknown field, a missing row, a `_complete` declaration, an
     *                          emptied Arabic name, or a slug already taken
     */
    public function patch(string $key, int $id, array $changes): PatchResult
    {
        if (array_key_exists('_complete', $changes)) {
            throw new RuntimeException(
                'A partial update must not declare `_complete`: that marker belongs to the full-REPLACE '
                .'lookup endpoint (§2.9.7), where an omitted `extra.*` key is CLEARED — and stored as 0 '
                .'for an integer column. Here an omitted key is left alone.'
            );
        }

        if ($changes === []) {
            throw new RuntimeException('Partial update called with no fields. Name at least one.');
        }

        $def = LookupWriter::definition($key);
        [$names, $extra] = self::split($def, $changes);

        return DB::transaction(function () use ($def, $id, $names, $extra): PatchResult {
            $current = DB::table($def['master'])->where('id', $id)
                ->first(array_merge(['id'], array_keys($def['extra'])));

            if (! is_object($current)) {
                throw new RuntimeException("Row {$id} does not exist in {$def['master']}. A partial update never creates.");
            }
            $row = Row::cast($current);

            [$write, $diff] = self::extraDiff($def, $row, $extra);

            // Uniqueness before anything is written, so a refusal leaves the row exactly as it was.
            self::assertSlugsFree($def, $id, $write);

            $nameDiff = self::applyNames($def, $id, $names);
            $changed = array_merge($diff, $nameDiff);

            if ($changed === []) {
                // The second run of the same edit. `updated_at` is deliberately not bumped: a
                // no-op that moved it would move the compat payload's timestamp with it (D-17).
                return new PatchResult;
            }

            if ($write !== []) {
                $write['updated_at'] = now();
                DB::table($def['master'])->where('id', $id)->update($write);
            }

            // Every lookup is in `catalog/meta` and in the compat payloads built from it.
            $this->cache->flush(1);
            foreach (DB::table('storefronts')->where('is_active', true)->pluck('id') as $storefrontId) {
                if (is_numeric($storefrontId)) {
                    $this->cache->flush((int) $storefrontId);
                }
            }

            return new PatchResult(changes: $changed);
        });
    }

    /**
     * What a caller may name on this list — `name.ar`, `name.en`, and one `extra.*` per declared
     * column. Published so a screen or an importer can validate before it writes.
     *
     * @return list<string>
     */
    public static function addressableFields(string $key): array
    {
        $def = LookupWriter::definition($key);
        $out = ['name.ar', 'name.en'];
        foreach (array_keys($def['extra']) as $column) {
            $out[] = 'extra.'.$column;
        }

        return $out;
    }

    // ── pieces ───────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>}  $def
     * @param  array<string, mixed>  $changes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function split(array $def, array $changes): array
    {
        $names = [];
        $extra = [];

        foreach ($changes as $field => $value) {
            if ($field === 'name.ar' || $field === 'name.en') {
                $names[substr($field, 5)] = $value;

                continue;
            }
            if (str_starts_with($field, 'extra.')) {
                $column = substr($field, 6);
                if (array_key_exists($column, $def['extra'])) {
                    $extra[$column] = $value;

                    continue;
                }
            }

            throw new RuntimeException(
                "[{$field}] is not a field a partial update may change on list [{$def['key']}]. Addressable: "
                .implode(', ', self::addressableFields($def['key'])).'.'
            );
        }

        return [$names, $extra];
    }

    /**
     * The extra columns that would actually change, coerced exactly as the full-replace path
     * coerces them — including the upper-cased hex (deviation D-07), so the two never store one
     * colour in two cases.
     *
     * @param  array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>}  $def
     * @param  array<string, mixed>  $extra
     * @return array{0: array<string, mixed>, 1: array<string, array{from: string|null, to: string|null}>}
     */
    private static function extraDiff(array $def, stdClass $row, array $extra): array
    {
        $write = [];
        $diff = [];

        foreach ($extra as $column => $raw) {
            $field = $def['extra'][$column];
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'string';

            /** @var int|string|null $to every arm below answers one of these three */
            $to = match ($type) {
                'integer' => Coerce::nint($raw),
                'boolean' => Coerce::bool($raw) ? 1 : 0,
                'hex' => ($hex = Coerce::nstr($raw)) === null ? null : strtoupper($hex),
                'slug' => LegacySlug::make(Coerce::str($raw)),
                default => Coerce::nstr($raw),
            };

            if ($type === 'slug' && $to === '') {
                throw new RuntimeException("[extra.{$column}] cannot be emptied: a slug is a URL.");
            }

            $from = self::stored($row, $column);
            $toString = $to === null ? null : (string) $to;

            if ($from === $toString) {
                continue;
            }

            $write[$column] = $to;
            $diff['extra.'.$column] = ['from' => $from, 'to' => $toString];
        }

        return [$write, $diff];
    }

    /**
     * A patched slug must not already belong to another row.
     *
     * The form path gets this from `Rule::unique(...)->ignore($id)`. A patcher has no validator,
     * so it asks the table — before the write, so a refusal changes nothing.
     *
     * @param  array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>}  $def
     * @param  array<string, mixed>  $write
     */
    private static function assertSlugsFree(array $def, int $id, array $write): void
    {
        foreach ($def['extra'] as $column => $field) {
            if ((is_string($field['type'] ?? null) ? $field['type'] : 'string') !== 'slug') {
                continue;
            }
            if (! array_key_exists($column, $write)) {
                continue;
            }

            $taken = DB::table($def['master'])
                ->where($column, $write[$column])->where('id', '!=', $id)->exists();

            if ($taken) {
                $value = Coerce::str($write[$column]);
                throw new RuntimeException("[extra.{$column}] = [{$value}] is already used by another row in [{$def['key']}].");
            }
        }
    }

    /**
     * The names, per locale, only the ones named.
     *
     * @param  array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>}  $def
     * @param  array<string, mixed>  $names
     * @return array<string, array{from: string|null, to: string|null}>
     */
    private static function applyNames(array $def, int $id, array $names): array
    {
        $diff = [];

        foreach ($names as $locale => $raw) {
            $to = Coerce::nstr($raw);
            $to = $to === null || trim($to) === '' ? null : trim($to);

            $from = DB::table($def['translations'])
                ->where($def['fk'], $id)->where('locale', $locale)->value('name');
            $from = $from === null ? null : Coerce::str($from);

            if ($from === $to) {
                continue;
            }

            if ($to === null) {
                if ($locale === 'ar') {
                    // `LookupWriter` refuses this too: a lookup with no Arabic name is a blank row
                    // in every filter and every spec block on an Arabic-first storefront.
                    throw new RuntimeException(ManageText::t('lookups.name_ar_not_emptiable', 'الاسم العربي مطلوب ولا يمكن إفراغه.'));
                }
                DB::table($def['translations'])->where($def['fk'], $id)->where('locale', $locale)->delete();
                $diff['name.'.$locale] = ['from' => $from, 'to' => null];

                continue;
            }

            DB::table($def['translations'])->updateOrInsert(
                [$def['fk'] => $id, 'locale' => $locale],
                ['name' => $to],
            );
            $diff['name.'.$locale] = ['from' => $from, 'to' => $to];
        }

        return $diff;
    }

    private static function stored(stdClass $row, string $column): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return $value === null ? null : Coerce::str($value);
    }
}
