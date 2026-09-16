<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\PreSwitch;
use App\Domain\Catalog\UnitCleanup;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                    $parts[] = Coerce::str($column).': '.Coerce::int($count);
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
            'pre_switch_notice' => PreSwitch::noticeFor('units'),
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'from' => ['required', 'integer', 'exists:catalog_units,id'],
            'into' => ['required', 'integer', 'exists:catalog_units,id'],
        ]));

        $moved = $this->units->merge(Coerce::int($data['from'] ?? null), Coerce::int($data['into'] ?? null));

        return back()->with('status', ManageText::t(
            'units.merged',
            'تم نقل :count مواصفة، وأُحيلت الوحدة القديمة للتقاعد.',
            ['count' => $moved],
        ));
    }

    public function retire(Request $request, int $unit): RedirectResponse
    {
        $this->units->retire($unit);

        return back()->with('status', ManageText::t('units.retire_done', 'أُخرجت الوحدة من القوائم. لا شيء حُذف: يمكن إعادتها في أي وقت.'));
    }

    public function restore(Request $request, int $unit): RedirectResponse
    {
        $this->units->restore($unit);

        return back()->with('status', ManageText::t('units.restore_done', 'أُعيدت الوحدة إلى القوائم.'));
    }
}
