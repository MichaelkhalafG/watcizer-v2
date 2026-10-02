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
 * Three separate actions (rework, developer-approved 2026-10-01): `update` saves the settings and
 * never the pause; `pause` sets ONLY the pause (it used to post the whole form, so a half-typed
 * edit was saved — or refused — with it); `replan` re-plans THIS week from the saved settings. A
 * save alone never touches a week already prepared, which is how "the audience didn't save" looked
 * from the screen: the week had been planned on Monday from the old one.
 *
 * Admin only (`MANAGE_STOREFRONTS`), scoped to the storefront: switching the audience to customers
 * mails real people, so it is a storefront-owner decision. Every change is activity-logged.
 */
final class ReEngagementController
{
    public function index(Storefront $storefront): Response
    {
        $settings = ReEngagement::settings($storefront->id);
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
                'team_emails' => $settings['team_emails'],
                'manual_product_ids' => $settings['manual_product_ids'],
            ],
            'picks' => ['min' => ReEngagement::MIN_PRODUCTS, 'max' => ReEngagement::PER_EMAIL],
            // For the status line at the top: this ISO week, when the next run is planned, and how many
            // customers a run would reach today.
            'state' => [
                'week' => now()->format('o-\WW'),
                'next_plan_at' => ReEngagement::nextPlanAt()->toDateTimeString(),
                'customers_estimate' => ReEngagement::audienceEstimate($storefront->id),
            ],
            'runs' => $runs,
            'optouts' => DB::table('core_marketing_optouts')->where('storefront_id', $storefront->id)->count(),
        ]);
    }

    public function update(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'audience' => ['required', 'string', Rule::in(['team', 'customers'])],
            // The team's addresses, one per chip (R7): each must be an address; at most 20.
            'team_emails' => ['present', 'array', 'max:20'],
            'team_emails.*' => ['required', 'string', 'max:190', 'email:rfc'],
            // The team's picks for the next e-mail (R4): none (automatic) or MIN..PER_EMAIL products
            // placed on THIS storefront, in order.
            'manual_product_ids' => ['present', 'array', 'max:'.ReEngagement::PER_EMAIL],
            'manual_product_ids.*' => ['integer', 'distinct', Rule::exists('storefront_product', 'product_id')->where('storefront_id', $storefront->id)],
        ], [
            'team_emails.*.email' => ManageText::t('reengagement.email_invalid', '«:input» ليس بريداً إلكترونياً صحيحاً.'),
            'team_emails.max' => ManageText::t('reengagement.emails_max', 'يمكن إضافة 20 بريداً على الأكثر.'),
            'manual_product_ids.*.exists' => ManageText::t('reengagement.pick_foreign', 'هذا المنتج غير موجود في هذا المتجر.'),
        ]));
        $picks = Coerce::intList($data['manual_product_ids'] ?? []);
        if ($picks !== [] && count($picks) < ReEngagement::MIN_PRODUCTS) {
            return back()->withErrors(['manual_product_ids' => ManageText::t('reengagement.picks_min', 'اختر :min منتجات على الأقل، أو اترك القائمة فارغة للاختيار التلقائي.', ['min' => ReEngagement::MIN_PRODUCTS])]);
        }
        $emails = [];
        foreach (array_map(fn (mixed $e): string => Coerce::str($e), Coerce::arr($data['team_emails'] ?? [])) as $email) {
            $emails[mb_strtolower(trim($email))] = true;                  // one of each, whatever the case
        }
        $before = self::logFields($storefront->id);
        DB::table('core_reengagement_settings')->updateOrInsert(['storefront_id' => $storefront->id], [
            'audience' => Coerce::str($data['audience'] ?? null) === 'customers' ? 'customers' : 'team',
            'team_emails' => $emails === [] ? null : implode("\n", array_keys($emails)),
            'manual_product_ids' => $picks === [] ? null : (string) json_encode($picks),
            'updated_at' => now(),
            'created_at' => DB::raw('COALESCE(created_at, NOW())'),
        ]);
        ActivityLog::record('core_reengagement_settings', $storefront->id, ActivityLog::UPDATED, $before, self::logFields($storefront->id),
            label: ManageText::t('reengagement.title', 'رسائل العودة'), storefrontId: $storefront->id);

        return back()->with('status', self::pendingRun($storefront->id)
            ? ManageText::t('reengagement.saved_pending', 'تم الحفظ. رسالة هذا الأسبوع جُهّزت بالإعدادات السابقة: أعد تجهيزها لتطبيق الجديدة، وإلا تُطبَّق من الإثنين القادم.')
            : ManageText::t('reengagement.saved_next', 'تم الحفظ. تُطبَّق على الرسالة القادمة.'));
    }

    /** Pause or resume — ONLY that; the rest of the form is left as saved. */
    public function pause(Request $request, Storefront $storefront): RedirectResponse
    {
        $paused = Coerce::bool(Coerce::arr($request->validate(['paused' => ['required', 'boolean']]))['paused'] ?? null);
        $before = self::logFields($storefront->id);
        DB::table('core_reengagement_settings')->updateOrInsert(['storefront_id' => $storefront->id], [
            'paused' => $paused,
            'updated_at' => now(),
            'created_at' => DB::raw('COALESCE(created_at, NOW())'),
        ]);
        ActivityLog::record('core_reengagement_settings', $storefront->id, ActivityLog::UPDATED, $before, self::logFields($storefront->id),
            label: ManageText::t('reengagement.title', 'رسائل العودة'), storefrontId: $storefront->id);

        return back()->with('status', $paused
            ? ManageText::t('reengagement.paused_done', 'أُوقفت: لن تُرسل أي رسالة حتى تستأنفها.')
            : ManageText::t('reengagement.resumed_done', 'استؤنفت: تُجهَّز الرسالة القادمة يوم الإثنين كالمعتاد.'));
    }

    /** Re-plan THIS week from the saved settings: rebuilds who and what, and mails the team a fresh preview. */
    public function replan(Storefront $storefront, ReEngagement $engine): RedirectResponse
    {
        $status = DB::table('core_reengagement_runs')->where('storefront_id', $storefront->id)->where('week', now()->format('o-\WW'))->value('status');
        if ($status === 'sent') {
            return back()->with('error', ManageText::t('reengagement.replan_sent', 'رسالة هذا الأسبوع أُرسلت بالفعل؛ تُجهَّز التالية يوم الإثنين.'));
        }
        if (ReEngagement::settings($storefront->id)['team_emails'] === []) {
            return back()->with('error', ManageText::t('reengagement.replan_no_team', 'أضف بريداً واحداً على الأقل للفريق واحفظ أولاً: المعاينة شرط قبل أي إرسال.'));
        }
        $result = $engine->plan($storefront->id);
        ActivityLog::record('core_reengagement_runs', $result['run_id'] ?? 0, ActivityLog::UPDATED, [], ['week' => now()->format('o-\WW'), 'recipients' => $result['recipients']],
            label: ManageText::t('reengagement.replan', 'أعد تجهيز رسالة هذا الأسبوع'), storefrontId: $storefront->id);

        return back()->with('status', ManageText::t('reengagement.replanned', 'أُعيد التجهيز: :count مستلم، وأُرسلت المعاينة للفريق. تُرسل بعد 24 ساعة إلا إذا أوقفتها.', ['count' => $result['recipients']]));
    }

    /** This week has a run that is prepared but not sent (waiting its 24 hours, or paused). */
    private static function pendingRun(int $storefrontId): bool
    {
        return DB::table('core_reengagement_runs')->where('storefront_id', $storefrontId)->where('week', now()->format('o-\WW'))
            ->where('status', '!=', 'sent')->exists();
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
