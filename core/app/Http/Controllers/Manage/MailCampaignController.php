<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Campaigns\EmailCampaigns;
use App\Domain\Campaigns\ListUpload;
use App\Domain\Campaigns\Xlsx;
use App\Domain\Notifications\CartReminders;
use App\Domain\Notifications\StorefrontMailer;
use App\Domain\Promotions\PromotionRules;
use App\Mail\CampaignMail;
use App\Mail\CartReminderMail;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontIdentity;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * /manage/storefronts/{storefront}/mail-campaigns — the e-mail campaigns and the abandoned-cart e-mail (2026-11-24, plan
 * items 1 and 5). Admin only (`MANAGE_STOREFRONTS`), scoped to the shop, like the weekly e-mail: sending mails real
 * people. Every change is activity-logged.
 *
 *  - The abandoned-cart card: the switch (off until the team turns it on), the delay, the code's terms, a test send to
 *    the signed-in staff address, and the numbers.
 *  - A campaign in six steps: audience (ready filters or an uploaded list, the count before sending), message, optional
 *    code, preview, test send to myself, send now or schedule. Nothing reaches a customer before "send"; a test send goes
 *    only to the signed-in staff address.
 */
final class MailCampaignController
{
    public function index(Storefront $storefront): Response
    {
        $sf = (int) $storefront->id;
        $campaigns = [];
        foreach (DB::table('core_email_campaigns')->where('storefront_id', $sf)->orderByDesc('id')->limit(50)->get() as $raw) {
            $c = Row::cast($raw);
            $campaigns[] = self::campaignProps($c) + ['progress' => EmailCampaigns::progress(Row::int($c, 'id'))];
        }
        $mailbox = DB::table('storefront_mailboxes')->where('storefront_id', $sf)->first(['daily_cap', 'transactional_reserve']);
        $m = $mailbox === null ? null : Row::cast($mailbox);

        return Inertia::render('Manage/MailCampaigns/Index', [
            'storefront' => ['id' => $sf, 'code' => $storefront->code, 'name' => $storefront->name],
            'reminder' => CartReminders::settings($sf) + ['totals' => CartReminders::totals($sf)],
            'campaigns' => $campaigns,
            'lists' => DB::table('core_email_lists')->where('storefront_id', $sf)->orderByDesc('id')->limit(50)->get(['id', 'name', 'rows', 'consent_at'])
                ->map(fn ($r): array => ['id' => Row::int(Row::cast($r), 'id'), 'name' => Row::str(Row::cast($r), 'name'), 'rows' => Row::int(Row::cast($r), 'rows'), 'consent_at' => Row::str(Row::cast($r), 'consent_at')])->all(),
            'brands' => DB::table('catalog_brands as b')->join('catalog_brand_translations as t', fn (JoinClause $j) => $j->on('t.brand_id', '=', 'b.id')->where('t.locale', '=', 'en'))
                ->where('b.is_active', 1)->orderBy('t.name')->get(['b.id', 't.name'])->map(fn ($r): array => ['id' => Row::int(Row::cast($r), 'id'), 'name' => Row::str(Row::cast($r), 'name')])->all(),
            'categories' => DB::table('storefront_categories as c')->join('storefront_category_translations as t', fn (JoinClause $j) => $j->on('t.storefront_category_id', '=', 'c.id')->where('t.locale', '=', 'ar'))
                ->where('c.storefront_id', $sf)->where('c.depth', '<=', 1)->orderBy('c.path')->get(['c.id', 't.name', 'c.depth'])
                ->map(fn ($r): array => ['id' => Row::int(Row::cast($r), 'id'), 'name' => Row::str(Row::cast($r), 'name'), 'depth' => Row::int(Row::cast($r), 'depth')])->all(),
            'limits' => [
                'money_rewards' => PromotionRules::moneyRewardsEnabled($sf),
                'daily_cap' => $m === null ? 0 : Row::int($m, 'daily_cap'),
                'reserve' => $m === null ? 0 : Row::int($m, 'transactional_reserve'),
                'per_run' => config()->integer('notifications.bulk.per_run'),
                'max_rows' => ListUpload::MAX_ROWS,
            ],
        ]);
    }

    public function reminder(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'enabled' => ['required', 'boolean'],
            'delay_hours' => ['required', 'integer', 'min:1', 'max:336'],
            'with_code' => ['required', 'boolean'],
            'percent' => ['required', 'numeric', 'min:1', 'max:90'],
            'cap' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'valid_days' => ['required', 'integer', 'min:1', 'max:60'],
        ]));
        $sf = (int) $storefront->id;
        $before = CartReminders::settings($sf);
        $after = [
            'enabled' => (bool) $data['enabled'], 'delay_hours' => Coerce::int($data['delay_hours']), 'with_code' => (bool) $data['with_code'],
            'percent' => Coerce::float($data['percent']), 'cap' => Coerce::nfloat($data['cap'] ?? null), 'valid_days' => Coerce::int($data['valid_days']),
        ];
        CartReminders::saveSettings($sf, $after, Coerce::nint($request->user()?->getAuthIdentifier()));
        ActivityLog::record('core_cart_reminder_settings', $sf, ActivityLog::UPDATED, $before, $after,
            label: ManageText::t('mailcampaigns.reminder_title', 'رسالة السلة المتروكة'), storefrontId: $sf);

        return back()->with('status', ManageText::t('mailcampaigns.reminder_saved', 'حُفظت إعدادات رسالة السلة المتروكة.'));
    }

    /** A sample of the abandoned-cart e-mail to the signed-in staff address only. */
    public function reminderTest(Request $request, Storefront $storefront): RedirectResponse
    {
        $email = self::staffEmail($request);
        $data = CartReminders::sample((int) $storefront->id, $email);
        self::sendNow((int) $storefront->id, $email, new CartReminderMail($data, StorefrontIdentity::for((int) $storefront->id)));

        return back()->with('status', ManageText::t('mailcampaigns.test_sent', 'أُرسلت رسالة تجربة إلى :email.', ['email' => $email]));
    }

    /** The live count of an audience: who it reaches, how many unsubscribed are left out, and the days the throttle needs. */
    public function count(Request $request, Storefront $storefront): JsonResponse
    {
        $sf = (int) $storefront->id;
        $result = EmailCampaigns::resolve($sf, EmailCampaigns::audience(Coerce::arr($request->input('audience'))));
        $n = count($result['recipients']);

        return response()->json(['count' => $n, 'held' => $result['held'], 'unsubscribed' => $result['unsubscribed'], 'days' => EmailCampaigns::daysToSend($sf, $n)]);
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $row = self::validated($request, $sf);
        $id = (int) DB::table('core_email_campaigns')->insertGetId($row + ['storefront_id' => $sf, 'status' => 'draft', 'created_by' => Coerce::nint($request->user()?->getAuthIdentifier()), 'created_at' => now(), 'updated_at' => now()]);
        ActivityLog::record('core_email_campaigns', $id, ActivityLog::CREATED, [], $row, label: Coerce::str($row['name']), storefrontId: $sf);

        return back()->with('status', ManageText::t('mailcampaigns.saved', 'حُفظت الحملة كمسودة.'))->with('campaign_id', $id);
    }

    public function update(Request $request, Storefront $storefront, int $campaign): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $c = self::own($sf, $campaign);
        if (! in_array(Row::str($c, 'status'), ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => ManageText::t('mailcampaigns.locked', 'الحملة بدأت الإرسال؛ لا يمكن تعديلها. انسخها في حملة جديدة.')]);
        }
        $row = self::validated($request, $sf);
        DB::table('core_email_campaigns')->where('id', $campaign)->update($row + ['updated_at' => now()]);
        ActivityLog::record('core_email_campaigns', $campaign, ActivityLog::UPDATED, self::campaignProps($c), $row, label: Coerce::str($row['name']), storefrontId: $sf);

        return back()->with('status', ManageText::t('mailcampaigns.saved', 'حُفظت الحملة كمسودة.'));
    }

    /** The e-mail as a recipient sees it, with a sample code, as HTML (the preview step). */
    public function preview(Storefront $storefront, int $campaign): HttpResponse
    {
        $sf = (int) $storefront->id;
        $c = self::own($sf, $campaign);

        return response((new CampaignMail(EmailCampaigns::message($c, 'preview@example.test', self::sampleCode($c), StorefrontUrls::frontend($sf)), StorefrontIdentity::for($sf)))->render())
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('X-Robots-Tag', 'noindex');
    }

    /** The campaign to the signed-in staff address only. */
    public function test(Request $request, Storefront $storefront, int $campaign): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $c = self::own($sf, $campaign);
        $email = self::staffEmail($request);
        self::sendNow($sf, $email, new CampaignMail(EmailCampaigns::message($c, $email, self::sampleCode($c), StorefrontUrls::frontend($sf)), StorefrontIdentity::for($sf)));

        return back()->with('status', ManageText::t('mailcampaigns.test_sent', 'أُرسلت رسالة تجربة إلى :email.', ['email' => $email]));
    }

    /** Send now (the recipients are fixed and the throttle starts at the next pass) or schedule. */
    public function send(Request $request, Storefront $storefront, int $campaign): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $c = self::own($sf, $campaign);
        $data = Coerce::arr($request->validate(['when' => ['required', 'in:now,schedule'], 'scheduled_at' => ['nullable', 'required_if:when,schedule', 'date', 'after:now']]));
        if (! in_array(Row::str($c, 'status'), ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => ManageText::t('mailcampaigns.locked', 'الحملة بدأت الإرسال؛ لا يمكن تعديلها. انسخها في حملة جديدة.')]);
        }
        if ($data['when'] === 'schedule') {
            DB::table('core_email_campaigns')->where('id', $campaign)->update(['status' => 'scheduled', 'scheduled_at' => Coerce::str($data['scheduled_at']), 'updated_at' => now()]);
            $message = ManageText::t('mailcampaigns.scheduled', 'جُدولت الحملة.');
        } else {
            $n = EmailCampaigns::start($campaign);
            $message = ManageText::t('mailcampaigns.started', 'بدأ الإرسال إلى :count عنوانًا، على دفعات.', ['count' => $n]);
        }
        ActivityLog::record('core_email_campaigns', $campaign, ActivityLog::UPDATED, ['status' => Row::str($c, 'status')], ['status' => $data['when'] === 'schedule' ? 'scheduled' : 'sending'], label: Row::str($c, 'name'), storefrontId: $sf);

        return back()->with('status', $message);
    }

    public function cancel(Storefront $storefront, int $campaign): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $c = self::own($sf, $campaign);
        DB::transaction(function () use ($campaign): void {
            DB::table('core_email_campaigns')->where('id', $campaign)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('core_email_campaign_recipients')->where('campaign_id', $campaign)->where('status', 'pending')->update(['status' => 'skipped', 'skip_reason' => 'cancelled']);
        });
        ActivityLog::record('core_email_campaigns', $campaign, ActivityLog::UPDATED, ['status' => Row::str($c, 'status')], ['status' => 'cancelled'], label: Row::str($c, 'name'), storefrontId: $sf);

        return back()->with('status', ManageText::t('mailcampaigns.cancelled', 'أُوقفت الحملة. ما أُرسل لا يُسترجع.'));
    }

    /** The list template: e-mail, name, optional phone. */
    public function template(): HttpResponse
    {
        return response(Xlsx::write([ListUpload::HEADER]), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="email-list-template.xlsx"',
        ]);
    }

    /**
     * An uploaded list: checked and reported (errors, duplicates, unsubscribed) WITHOUT saving; saved only with
     * `save` and the consent tick, keeping the valid rows.
     */
    public function upload(Request $request, Storefront $storefront): JsonResponse
    {
        $sf = (int) $storefront->id;
        $data = Coerce::arr($request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt'],
            'name' => ['nullable', 'string', 'max:120'],
            'save' => ['nullable', 'boolean'],
            'consent' => ['nullable', 'boolean'],
        ]));
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['ok' => false, 'errors' => ['file' => [ManageText::t('mailcampaigns.upload_bad', 'ارفع ملف ‎.xlsx‎ أو ‎.csv‎ من النموذج.')]]], 422);
        }
        try {
            $report = ListUpload::parse($sf, (string) $file->getRealPath(), (string) $file->getClientOriginalName());
        } catch (Throwable) {
            return response()->json(['ok' => false, 'errors' => ['file' => [ManageText::t('mailcampaigns.upload_bad', 'ارفع ملف ‎.xlsx‎ أو ‎.csv‎ من النموذج.')]]], 422);
        }
        $out = ['ok' => true, 'valid' => count($report['rows']), 'errors' => $report['errors'], 'duplicates' => $report['duplicates'], 'unsubscribed' => $report['unsubscribed'], 'read' => $report['read'], 'list_id' => null];
        if ((bool) ($data['save'] ?? false)) {
            if (! (bool) ($data['consent'] ?? false)) {
                return response()->json(['ok' => false, 'errors' => ['consent' => [ManageText::t('mailcampaigns.consent_required', 'أكّد أن هؤلاء الأشخاص وافقوا على تلقّي رسائلنا قبل الحفظ.')]]] + $out, 422);
            }
            if ($report['rows'] === []) {
                return response()->json(['ok' => false, 'errors' => ['file' => [ManageText::t('mailcampaigns.upload_empty', 'لا يوجد في الملف سطر صالح للحفظ.')]]] + $out, 422);
            }
            $userId = Coerce::int($request->user()?->getAuthIdentifier());
            $name = trim(Coerce::str($data['name'] ?? '')) ?: (string) $file->getClientOriginalName();
            $out['list_id'] = ListUpload::save($sf, $name, $report['rows'], $userId);
            ActivityLog::record('core_email_lists', $out['list_id'], ActivityLog::CREATED, [], ['name' => $name, 'rows' => count($report['rows']), 'consent' => true], label: $name, storefrontId: $sf);
        }

        return response()->json($out);
    }

    /** @return array<string, mixed> the columns a save writes, validated */
    private static function validated(Request $request, int $sf): array
    {
        $data = Coerce::arr($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject_ar' => ['required', 'string', 'max:150'],
            'subject_en' => ['required', 'string', 'max:150'],
            'body_ar' => ['required', 'string', 'max:5000'],
            'body_en' => ['required', 'string', 'max:5000'],
            'product_ids' => ['array', 'max:6'],
            'product_ids.*' => ['integer', 'min:1'],
            'audience' => ['required', 'array'],
            'with_code' => ['required', 'boolean'],
            'code_percent' => ['nullable', 'required_if:with_code,true', 'numeric', 'min:1', 'max:90'],
            'code_cap' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'code_days' => ['nullable', 'required_if:with_code,true', 'integer', 'min:1', 'max:60'],
        ]));
        $audience = EmailCampaigns::audience(Coerce::arr($data['audience']));
        if ($audience['list_id'] !== null && DB::table('core_email_lists')->where('id', $audience['list_id'])->where('storefront_id', $sf)->doesntExist()) {
            throw ValidationException::withMessages(['audience.list_id' => ManageText::t('mailcampaigns.list_unknown', 'اختر قائمة من قوائم هذا المتجر.')]);
        }
        $withCode = (bool) $data['with_code'];
        if ($withCode && ! PromotionRules::moneyRewardsEnabled($sf)) {
            throw ValidationException::withMessages(['with_code' => ManageText::t('wheel.money_off', 'الخصومات المالية مغلقة في هذا المتجر (المتاجر ← الخصومات المالية)، فلا يمكن منح هذه الجائزة. افتحها، أو اختر شحنًا مجانيًا أو هدية أو «حظًا أوفر».')]);
        }

        return [
            'name' => trim(Coerce::str($data['name'])), 'subject_ar' => trim(Coerce::str($data['subject_ar'])), 'subject_en' => trim(Coerce::str($data['subject_en'])),
            'body_ar' => Coerce::str($data['body_ar']), 'body_en' => Coerce::str($data['body_en']),
            'product_ids' => json_encode(array_values(array_map(fn ($v): int => Coerce::int($v), Coerce::arr($data['product_ids'] ?? [])))),
            'audience' => json_encode($audience), 'list_id' => $audience['list_id'],
            'code_percent' => $withCode ? number_format(Coerce::float($data['code_percent'] ?? null), 2, '.', '') : null,
            'code_cap' => $withCode && Coerce::nfloat($data['code_cap'] ?? null) !== null ? number_format(Coerce::float($data['code_cap']), 2, '.', '') : null,
            'code_days' => $withCode ? Coerce::int($data['code_days'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function campaignProps(\stdClass $c): array
    {
        return [
            'id' => Row::int($c, 'id'), 'name' => Row::str($c, 'name'), 'status' => Row::str($c, 'status'),
            'subject_ar' => Row::str($c, 'subject_ar'), 'subject_en' => Row::str($c, 'subject_en'),
            'body_ar' => Row::str($c, 'body_ar'), 'body_en' => Row::str($c, 'body_en'),
            'product_ids' => array_values(array_map(fn ($v): int => Coerce::int($v), Coerce::arr(json_decode((string) Row::nstr($c, 'product_ids'), true)))),
            'audience' => EmailCampaigns::audience(Coerce::arr(json_decode(Row::str($c, 'audience'), true))),
            'with_code' => Row::nfloat($c, 'code_percent') !== null,
            'code_percent' => Row::nfloat($c, 'code_percent'), 'code_cap' => Row::nfloat($c, 'code_cap'), 'code_days' => Row::nint($c, 'code_days'),
            'scheduled_at' => Row::nstr($c, 'scheduled_at'), 'started_at' => Row::nstr($c, 'started_at'), 'finished_at' => Row::nstr($c, 'finished_at'),
            'recipients' => Row::int($c, 'recipients'),
        ];
    }

    private static function own(int $sf, int $campaign): \stdClass
    {
        $c = EmailCampaigns::row($campaign);
        abort_if($c === null || Row::int($c, 'storefront_id') !== $sf, 404);

        return $c;
    }

    /** @return array{code: string, percent: float, cap: float|null, expires_at: string}|null */
    private static function sampleCode(\stdClass $c): ?array
    {
        $percent = Row::nfloat($c, 'code_percent');

        return $percent === null ? null : ['code' => 'GIFT-SAMP-LE00', 'percent' => $percent, 'cap' => Row::nfloat($c, 'code_cap'), 'expires_at' => now()->addDays(Row::nint($c, 'code_days') ?? 7)->endOfDay()->toDateTimeString()];
    }

    private static function staffEmail(Request $request): string
    {
        $email = Coerce::str(DB::table('users')->where('id', Coerce::int($request->user()?->getAuthIdentifier()))->value('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['test' => ManageText::t('mailcampaigns.no_staff_email', 'حسابك بلا بريد إلكتروني صالح، فلا يمكن إرسال رسالة التجربة.')]);
        }

        return $email;
    }

    private static function sendNow(int $sf, string $email, Mailable $mail): void
    {
        try {
            StorefrontMailer::send($sf, $email, $mail);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['test' => ManageText::t('mailcampaigns.test_failed', 'تعذّر إرسال رسالة التجربة: :reason', ['reason' => class_basename($e)])]);
        }
    }
}
