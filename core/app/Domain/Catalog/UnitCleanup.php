<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Merging and retiring units of measurement (wave 4D, task C3).
 *
 * ── The one door, because a merge is eight UPDATEs that must all happen ──────────────────────
 *
 * `catalog_product_watch_specs` references `catalog_units` from **eight** columns — case size,
 * case thickness, band length, band width, water resistance, height, width, length. A merge that
 * repointed seven of them would leave the eighth pointing at a unit the screen has just told the
 * operator is gone, and the FK's `RESTRICT` would then refuse the retirement with an error nobody
 * can act on. So the column list lives here, once, and is derived from the SCHEMA rather than
 * typed: a ninth column added later is included without anybody remembering to.
 *
 * ── What this is for ─────────────────────────────────────────────────────────────────────────
 *
 * The legacy `size_types` table mixed physical units with garment and shoe sizes, and the
 * transform carries that across faithfully (decision 2026-09-06). The result, measured here on
 * 2026-09-14: 37 units, of which 31 are clothing or shoe sizes — and `M` is recorded as the unit of
 * 231 watch measurements, which is not a unit at all but the picker's fault. This class is how an
 * admin says "everything that says M means mm" and then takes M out of the picker.
 *
 * ── Two refusals ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. **A unit still in use cannot be retired.** Merge it first. Retiring a used unit would hide
 *     the row while 231 specs still point at it, so the product page would render a measurement
 *     with a unit the admin can no longer see or correct.
 *  2. **A merge target cannot be retired**, and nothing merges into itself. Both are the same
 *     mistake in two directions: moving data onto a row that is on its way out.
 */
final class UnitCleanup
{
    /** The table every unit reference lives in. */
    private const SPECS = 'catalog_product_watch_specs';

    /**
     * Every column of {@see self::SPECS} that points at a unit, read from the SCHEMA.
     *
     * Deriving beats declaring here: the eight columns were added by one migration and a ninth is
     * exactly the kind of thing that gets added without a list being updated — and the symptom
     * would be a merge that looks complete and leaves rows behind.
     *
     * @return list<string>
     */
    public static function referenceColumns(): array
    {
        $out = [];
        foreach (DB::select('SHOW COLUMNS FROM `'.self::SPECS.'`') as $column) {
            if (! is_object($column)) {
                continue;
            }
            $field = Coerce::str(Row::cast($column)->Field ?? null);
            if (str_ends_with($field, '_unit_id')) {
                $out[] = $field;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Every unit, with what uses it and whether it looks like a unit at all.
     *
     * ONE query for the usage of every unit across every column, not one query per unit per column
     * — the naive shape is 37 × 8 = 296 queries for one screen, which is the mistake the product
     * list already learned (AGENTS §2.24, "per page, never per row").
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $columns = self::referenceColumns();

        $usage = [];
        foreach ($columns as $column) {
            $rows = DB::table(self::SPECS)
                ->whereNotNull($column)
                ->select($column.' as unit_id')
                ->selectRaw('COUNT(*) as total')
                ->groupBy($column)
                ->get();

            foreach ($rows as $raw) {
                $row = Row::cast($raw);
                $unitId = Row::int($row, 'unit_id');
                $usage[$unitId][$column] = Row::int($row, 'total');
            }
        }

        $units = DB::table('catalog_units as u')
            ->leftJoin('catalog_unit_translations as ar', function (JoinClause $join): void {
                $join->on('ar.unit_id', '=', 'u.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('catalog_unit_translations as en', function (JoinClause $join): void {
                $join->on('en.unit_id', '=', 'u.id')->where('en.locale', '=', 'en');
            })
            ->orderBy('u.id')
            ->get(['u.id', 'u.code', 'u.retired_at', 'ar.name as name_ar', 'en.name as name_en']);

        $out = [];
        foreach ($units as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $code = Row::str($row, 'code');
            $byColumn = $usage[$id] ?? [];

            $out[] = [
                'id' => $id,
                'code' => $code,
                'name_ar' => Row::nstr($row, 'name_ar') ?? '',
                'name_en' => Row::nstr($row, 'name_en') ?? '',
                'retired_at' => Row::nstr($row, 'retired_at'),
                'used' => array_sum($byColumn),
                'used_by' => $byColumn,
                // A hint, never a rule: the screen sorts by it and the admin decides. Automatically
                // retiring everything that "looks like a size" would have retired `M` — which is
                // used 231 times and needs MERGING, not hiding.
                'looks_like_a_size' => self::looksLikeASize($code),
            ];
        }

        return $out;
    }

    /**
     * Point every reference at $into, then retire $from. Returns how many rows moved.
     *
     * @throws ValidationException
     */
    public function merge(int $from, int $into): int
    {
        $source = self::require($from);
        $target = self::require($into);

        $errors = [];
        if ($from === $into) {
            $errors['into'] = ManageText::t('units.merge_into_itself', 'لا يمكن دمج وحدة في نفسها.');
        }
        if (Row::nstr($target, 'retired_at') !== null) {
            $errors['into'] = ManageText::t('units.target_retired', 'الوحدة الهدف متقاعدة. اختر وحدة مستخدمة، أو أعِدها للخدمة أولًا.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($from, $into, $source): int {
            $moved = 0;
            foreach (self::referenceColumns() as $column) {
                // No `updated_at` here: `catalog_product_watch_specs` has NO timestamp columns —
                // it is one row per product, written by the form. Naming one was a 1054 on every
                // merge, which the first test of this method found.
                $moved += DB::table(self::SPECS)->where($column, $from)->update([$column => $into]);
            }

            /*
             * The source is retired in the SAME transaction, not left for a second click. A merge
             * that moved 231 rows and left the empty unit in the picker has done half a job, and
             * the half it left is the half that caused the mess.
             */
            DB::table('catalog_units')->where('id', $from)->update([
                'retired_at' => now(),
                'updated_at' => now(),
            ]);

            unset($source);

            return $moved;
        });
    }

    /**
     * Take a unit out of the pickers. Refuses while anything still points at it.
     *
     * @throws ValidationException
     */
    public function retire(int $unitId): void
    {
        self::require($unitId);

        $used = self::usageOf($unitId);
        if ($used > 0) {
            throw ValidationException::withMessages([
                'unit' => ManageText::t(
                    'units.retire_blocked',
                    'هذه الوحدة مستخدمة في :count مواصفة. ادمجها في وحدة أخرى أولًا، وإلا ستبقى المقاسات مرتبطة بوحدة لا يستطيع أحد رؤيتها أو تصحيحها.',
                    ['count' => $used],
                ),
            ]);
        }

        DB::table('catalog_units')->where('id', $unitId)->update([
            'retired_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Put it back in the pickers. Always allowed: nothing depends on a unit being hidden. */
    public function restore(int $unitId): void
    {
        self::require($unitId);

        DB::table('catalog_units')->where('id', $unitId)->update([
            'retired_at' => null,
            'updated_at' => now(),
        ]);
    }

    public static function usageOf(int $unitId): int
    {
        $total = 0;
        foreach (self::referenceColumns() as $column) {
            $total += DB::table(self::SPECS)->where($column, $unitId)->count();
        }

        return $total;
    }

    /**
     * `XS`, `XXXL`, `26`…`47`, `free-size` — a garment or shoe size wearing a unit's row.
     *
     * A hint for the screen's sort order and a badge, nothing more.
     */
    public static function looksLikeASize(string $code): bool
    {
        $code = mb_strtolower(trim($code));

        return $code === 'free-size'
            || preg_match('/^x*[sl]$/', $code) === 1          // s, l, xs, xl, xxxxxl
            || $code === 'm'
            || preg_match('/^\d{1,2}$/', $code) === 1;        // 26…47
    }

    /** @throws ValidationException when the id names no unit */
    private static function require(int $unitId): stdClass
    {
        $row = DB::table('catalog_units')->where('id', $unitId)->first(['id', 'code', 'retired_at']);
        if (! is_object($row)) {
            throw ValidationException::withMessages(['unit' => ManageText::t('units.not_found', 'لا توجد وحدة بهذا الرقم.')]);
        }

        return Row::cast($row);
    }
}
