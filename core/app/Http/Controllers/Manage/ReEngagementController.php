<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Notifications\ReEngagement;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /manage/storefronts/{storefront}/reengagement — the weekly "new picks for you" e-mail
 * (2026-10-01): the PAUSE switch, who receives it (the team only — the default — or customers),
 * the team's addresses (no shared default — R7), the products picked by hand for the NEXT e-mail
 * (none = chosen automatically — R4), and every run with its numbers.
 *
 * Admin only (`MANAGE_STOREFRONTS`), scoped to the storefront: switching the audience to customers
 * mails real people, so it is a storefront-owner decision. Every change is activity-logged.
 */
final class ReEngagementController
{
    public function index(Storefront $storefront): Response
    {
        $settings = ReEngagement::settings($storefront->id);
        $typed = DB::table('core_reengagement_settings')->where('storefront_id', $storefront->id)->value('team_emails');
        $runs = [];
        foreach (DB::table('core_reengagement_runs')->where('storefront_id', $storefront->id)->orderByDesc('id')->limit(20)->get() as $raw) {
            $r = Row::cast($raw);
            $runs[] = [
                'id' => Row::int($r, 'id'),
                'week' => Row::str($r, 'week'),
                'audience' => Row::str($r, 'audience'),
                'status' => Row::str($r, 'status'),
                'recipients' => Row::int($r, 'recipients'),
                'previewed_at' => Row::nstr($r, 'previewed_at'),
                'send_after' => Row::nstr($r, 'send_after'),
                'sent_at' => Row::nstr($r, 'sent_at'),
                'manual' => Row::nstr($r, 'manual_product_ids') !== null,
            ];
        }

        return Inertia::render('Manage/ReEngagement/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'settings' => [
                'paused' => $settings['paused'],
                'audience' => $settings['audience'],
                'team_emails' => is_string($typed) ? $typed : '',
                'team_effective' => $settings['team_emails'],
                'manual_product_ids' => $settings['manual_product_ids'],
            ],
            'picks' => ['min' => ReEngagement::MIN_PRODUCTS, 'max' => ReEngagement::PER_EMAIL],
            'runs' => $runs,
            'optouts' => DB::table('core_marketing_optouts')->where('storefront_id', $storefront->id)->count(),
        ]);
    }

    public function update(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'paused' => ['required', 'boolean'],
            'audience' => ['required', 'string', Rule::in(['team', 'customers'])],
            'team_emails' => ['nullable', 'string', 'max:2000'],
            // The team's picks for the next e-mail (R4): none (automatic) or MIN..PER_EMAIL products
            // placed on THIS storefront, in order.
            'manual_product_ids' => ['present', 'array', 'max:'.ReEngagement::PER_EMAIL],
            'manual_product_ids.*' => ['integer', 'distinct', Rule::exists('storefront_product', 'product_id')->where('storefront_id', $storefront->id)],
        ]));
        $picks = Coerce::intList($data['manual_product_ids'] ?? []);
        if ($picks !== [] && count($picks) < ReEngagement::MIN_PRODUCTS) {
            return back()->withErrors(['manual_product_ids' => ManageText::t('reengagement.picks_min', 'اختر :min منتجات على الأقل، أو اترك القائمة فارغة للاختيار التلقائي.', ['min' => ReEngagement::MIN_PRODUCTS])]);
        }
        $before = self::logFields($storefront->id);
        DB::table('core_reengagement_settings')->updateOrInsert(['storefront_id' => $storefront->id], [
            'paused' => Coerce::bool($data['paused'] ?? null),
            'audience' => Coerce::str($data['audience'] ?? null) === 'customers' ? 'customers' : 'team',
            'team_emails' => Coerce::nstr($data['team_emails'] ?? null),
            'manual_product_ids' => $picks === [] ? null : (string) json_encode($picks),
            'updated_at' => now(),
            'created_at' => DB::raw('COALESCE(created_at, NOW())'),
        ]);
        ActivityLog::record('core_reengagement_settings', $storefront->id, ActivityLog::UPDATED, $before, self::logFields($storefront->id),
            label: ManageText::t('reengagement.title', 'رسائل العودة'), storefrontId: $storefront->id);

        return back()->with('status', ManageText::t('reengagement.saved', 'تم حفظ إعدادات رسائل العودة.'));
    }

    /** @return array<string, mixed> */
    private static function logFields(int $storefrontId): array
    {
        $raw = DB::table('core_reengagement_settings')->where('storefront_id', $storefrontId)->first(['paused', 'audience', 'team_emails', 'manual_product_ids']);
        if (! is_object($raw)) {
            return ['paused' => false, 'audience' => 'team', 'team_emails' => null, 'manual_product_ids' => null];
        }
        $row = Row::cast($raw);

        return ['paused' => Row::bool($row, 'paused'), 'audience' => Row::str($row, 'audience'), 'team_emails' => Row::nstr($row, 'team_emails'),
            'manual_product_ids' => Row::nstr($row, 'manual_product_ids')];
    }
}
