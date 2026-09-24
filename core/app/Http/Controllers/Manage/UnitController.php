<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\UnitCleanup;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableExport;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The units cleanup screen (wave 4D, task C3) — the one the wave-1 decision put on the backlog.
 *
 * ── What the operator is looking at ──────────────────────────────────────────────────────────
 *
 * `catalog_units` came out of the legacy `size_types` table, which mixed two unrelated things:
 * physical units (mm, cm, inch, ATM, Bar) and garment/shoe SIZES (XS…XXXXXL, 26…47). The transform
 * carries it across faithfully — that was the decision on 2026-09-06, with "units cleanup UI" put
 * on the wave-4 backlog — so the watch form's unit picker currently offers 37 options of which 31
 * are clothing sizes.
 *
 * And the picker's thirty-one wrong answers have been taken: measured 2026-09-14, **`M` is the
 * recorded unit of 231 watch measurements** and `33` of one more. This screen is where an admin
 * says "everything that says M means mm" and then takes M out of the picker.
 *
 * ── Two actions, in the order they have to happen ────────────────────────────────────────────
 *
 *  1. **MERGE** — repoint every reference from one unit to another, then retire the source, in one
 *     transaction. This is the action that fixes data.
 *  2. **RETIRE** — take an UNUSED unit out of the pickers without deleting it. Refused while
 *     anything still points at it, which is what makes step 1 come first.
 *
 * Nothing deletes. Every `*_unit_id` column is a FK with `RESTRICT`, ids are preserved by the
 * transform (§2.9.6 rule 7), and hiding a row is reversible while deleting one is not.
 */
final class UnitController
{
    public function __construct(private readonly UnitCleanup $units) {}

    public function index(Request $request): Response|StreamedResponse
    {
        $units = UnitCleanup::all();

        // Sorted so the work is at the top: the sizes that are actually IN USE first (they need a
        // merge), then unused sizes (they can simply be retired), then the real units.
        usort($units, function (array $a, array $b): int {
            $rank = static fn (array $u): int => match (true) {
                $u['retired_at'] !== null => 3,
                $u['looks_like_a_size'] === true && Coerce::int($u['used']) > 0 => 0,
                $u['looks_like_a_size'] === true => 1,
                default => 2,
            };

            return [$rank($a), -Coerce::int($a['used']), Coerce::int($a['id'])]
                <=> [$rank($b), -Coerce::int($b['used']), Coerce::int($b['id'])];
        });

        /*
         * The cleanup worksheet. `used_by` is flattened to `table.column: n` pairs because WHERE a
         * unit is used is the whole question — 231 uses in one column is a merge, the same count
         * spread across four is a conversation.
         */
        $export = TableExport::wanted($request, 'units', [
            'code' => ManageText::t('storefronts.code', 'الرمز'),
            'name_ar' => ManageText::t('common.name_ar', 'الاسم (عربي)'),
            'name_en' => ManageText::t('common.name_en', 'الاسم (إنجليزي)'),
            'used' => ManageText::t('lookups.usage_count', 'مرات الاستخدام'),
            'used_by' => [ManageText::t('units.where', 'أين'), function (array $row): string {
                $parts = [];
                foreach (Coerce::arr($row['used_by'] ?? null) as $column => $count) {
                    $parts[] = self::columnLabel(Coerce::str($column)).': '.Coerce::int($count);
                }

                return implode(' | ', $parts);
            }],
            'looks_like_a_size' => ManageText::t('units.looks_like_a_size', 'يبدو مقاسًا'),
            'retired_at' => ManageText::t('units.retired_at', 'أُوقف في'),
        ], $units);
        if ($export !== null) {
            return $export;
        }

        return Inertia::render('Manage/Units/Index', [
            'units' => $units,
            'columns' => UnitCleanup::referenceColumns(),
            /*
             * The same honest notice every catalogue screen carries before the write-switch: these
             * tables are rebuilt from legacy on every run, so a merge done today is undone by the
             * next rebuild. The screen says so rather than letting an admin spend an afternoon on
             * it — and after the switch the notice disappears on its own.
             */
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'from' => ['required', 'integer', 'exists:catalog_units,id'],
            'into' => ['required', 'integer', 'exists:catalog_units,id'],
        ]));

        $from = Coerce::int($data['from'] ?? null);
        $into = Coerce::int($data['into'] ?? null);

        /*
         * Read BEFORE: the merge retires the source and empties its usage count, so afterwards the
         * row could no longer say what was actually moved. The unit's CODE goes in the label rather
         * than in the diff, because it is not what changed — `logFields()` is for the two verbs
         * that change the row's own columns, and a merge is a different shape.
         *
         * Both sides carry the SAME three keys on purpose. `ActivityLog::diff()` walks the union of
         * before and after, so a key present on one side alone reads as a field that was emptied:
         * an earlier version passed `logFields()` as the before and these three as the after, and
         * the row said `code: mm ← —`, which is a merged unit reporting that its code was deleted.
         */
        $target = self::unitCode($into);
        $before = ['merged_into' => null, 'specifications_moved' => null, 'retired' => false];

        $moved = $this->units->merge($from, $into);

        ActivityLog::record(
            'catalog_units',
            $from,
            ActivityLog::UPDATED,
            $before,
            ['merged_into' => $target, 'specifications_moved' => $moved, 'retired' => true],
            label: self::unitCode($from),
        );

        return back()->with('status', ManageText::t(
            'units.merged',
            'تم نقل :count مواصفة، وأُحيلت الوحدة القديمة للتقاعد.',
            ['count' => $moved],
        ));
    }

    public function retire(Request $request, int $unit): RedirectResponse
    {
        $before = self::logFields($unit);

        $this->units->retire($unit);

        ActivityLog::record(
            'catalog_units',
            $unit,
            ActivityLog::UPDATED,
            $before,
            self::logFields($unit),
            label: self::unitCode($unit),
        );

        return back()->with('status', ManageText::t('units.retire_done', 'أُخرجت الوحدة من القوائم. لا شيء حُذف: يمكن إعادتها في أي وقت.'));
    }

    public function restore(Request $request, int $unit): RedirectResponse
    {
        $before = self::logFields($unit);

        $this->units->restore($unit);

        ActivityLog::record(
            'catalog_units',
            $unit,
            ActivityLog::RESTORED,
            $before,
            self::logFields($unit),
            label: self::unitCode($unit),
        );

        return back()->with('status', ManageText::t('units.restore_done', 'أُعيدت الوحدة إلى القوائم.'));
    }

    /**
     * What a unit row is, for the log — the two fields a merge or a retirement actually moves.
     *
     * ── Why this screen is logged at all (2026-10-05) ───────────────────────────
     *
     * It was not, and the cost was concrete: 21 units were removed through this screen and nothing
     * anywhere recorded it, so when the rows turned up missing the only available explanation was
     * a guess — and the guess was wrong, and a restore from legacy nearly put the mess back. "Who
     * changed this?" had no answer for eight screens; this was one of them.
     *
     * @return array<string, mixed>
     */
    private static function logFields(int $id): array
    {
        $row = DB::table('catalog_units')->where('id', $id)->first(['code', 'retired_at']);
        if (! is_object($row)) {
            return [];
        }
        $unit = Row::cast($row);

        return [
            'code' => Row::str($unit, 'code'),
            'retired_at' => Row::nstr($unit, 'retired_at'),
        ];
    }

    /** The unit's CODE, which is what an operator calls it — `mm`, `atm`, `42`. */
    private static function unitCode(int $id): string
    {
        $code = DB::table('catalog_units')->where('id', $id)->value('code');

        return is_scalar($code) ? (string) $code : ('#'.$id);
    }

    /**
     * One `*_unit_id` column, as the measurement it holds (item 10, 2026-09-18).
     *
     * The same eight words `Units/Index.tsx` shows in its table — the screen had them and the CSV
     * export did not. `UnitCleanup::referenceColumns()` DISCOVERS these columns with `SHOW COLUMNS`
     * rather than declaring them, so a ninth one can appear without anybody editing this file: it
     * falls through to the raw column name, which is how it announces itself.
     */
    private static function columnLabel(string $column): string
    {
        return match ($column) {
            'case_size_unit_id' => ManageText::t('specs.field_case_size', 'قياس جسم الساعة'),
            'case_thickness_unit_id' => ManageText::t('specs.field_case_thickness', 'سماكة جسم الساعة'),
            'band_length_unit_id' => ManageText::t('specs.field_band_length', 'طول السوار'),
            'band_width_unit_id' => ManageText::t('specs.field_band_width', 'عرض السوار'),
            'water_resistance_unit_id' => ManageText::t('specs.field_water_resistance', 'مقاومة الماء'),
            'height_unit_id' => ManageText::t('specs.field_height', 'ارتفاع الساعة'),
            'width_unit_id' => ManageText::t('specs.field_width', 'عرض الساعة'),
            'length_unit_id' => ManageText::t('specs.field_length', 'طول الساعة'),
            default => $column,
        };
    }
}
