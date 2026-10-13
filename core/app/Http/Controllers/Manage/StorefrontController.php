<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Notifications\StorefrontMailer;
use App\Domain\Promotions\PromotionRules;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontMailbox;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontHasNoHost;
use App\Storefront\StorefrontSettingsForm;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /manage/storefronts — the admin-only storefront list and settings form (wave 4A, scope item 7).
 *
 * Creating and disabling a storefront is admin-only per AGENTS §2.7, and so is editing: the route
 * group carries `can:manage-storefronts`, which no data-entry grant includes.
 *
 * ── The finding this screen surfaced, and how it was settled ─────────────────────────────────
 *
 * `storefronts` used to be in `CoreChecksumCommand::CLEAN_TABLES`, so switch night's
 * drop-and-rebuild would have DROPPED it and `StorefrontSeeder::ensure()` would have recreated the
 * row from a hard-coded constant — losing everything an admin typed here, silently, on the night
 * the team goes live.
 *
 * **Settled 2026-09-11 with option (a)** and generalised into AGENTS §2.20: a table whose content
 * is authored in the DASHBOARD is never in the drop list. `storefronts`, `storefront_banners` and
 * `core_user_roles` are `DASHBOARD_TABLES` now; `core:drop-clean` refuses to touch them and
 * verifies they are still there afterwards. The other half of the same bug was the seeder itself
 * (`forceFill(...)->save()` rewrote these columns on EVERY transform run, so a rehearsal would
 * have reverted the screen even without the drop) — `ensure()` is insert-only. Both halves are
 * asserted by `tests/Feature/Manage/DashboardTablesTest.php`, and the real destructive path is
 * recorded in `docs/wave4a/DROP_CLEAN_2026-09-11.md`.
 *
 * The warning below therefore no longer describes the storefront table — it now says the thing
 * that IS still true before the write-switch, which is what the catalogue screens say too.
 */
final class StorefrontController
{
    public function __construct(private readonly StorefrontCache $cache) {}

    public function index(Request $request): Response|StreamedResponse
    {
        $table = TableQuery::for($request)
            ->sortable(['id', 'code', 'name', 'is_active', 'created_at'], default: 'id')
            ->searchable(['code', 'name', 'domain'])
            ->filterable(['is_active' => ['0', '1']])
            ->perPage(default: 25, max: 100)
            // Two rows today, and still worth exporting: this is the list somebody pastes into a
            // switch-night checklist. No payment credential is in this payload — those live on the
            // payments screen behind MANAGE_PAYMENTS, encrypted, and never leave the database.
            ->exportable([
                'id' => ManageText::t('common.id', 'الرقم'),
                // `الكود` here and `الرمز` on the screen are two different Arabic words for the same
                // column, so they are two keys on purpose: one key cannot hold both Arabics.
                // A STOREFRONT's code, which borrowed a product key and would have been
                // renamed to «الكود الداخلي» by the item-4 sweep. It is neither of the two
                // product codes.
                'code' => ManageText::t('storefronts.shop_code', 'رمز المتجر'),
                'name' => ManageText::t('common.name', 'الاسم'),
                'domain' => ManageText::t('common.domain', 'النطاق'),
                'locales' => [ManageText::t('storefronts.locales', 'اللغات'), fn (array $row): string => implode(' | ', array_map(
                    static fn (mixed $locale): string => Coerce::str($locale),
                    Coerce::arr($row['locales'] ?? null),
                ))],
                'default_locale' => ManageText::t('storefronts.edit_default_locale', 'اللغة الافتراضية'),
                'currency' => ManageText::t('common.currency', 'العملة'),
                'is_active' => ManageText::t('common.active', 'مفعّل'),
                'updated_at' => ManageText::t('storefronts.last_modified', 'آخر تعديل'),
            ], 'storefronts');

        $query = DB::table('storefronts')->select(['id', 'code', 'name', 'domain', 'locales', 'default_locale', 'currency', 'is_active', 'updated_at']);

        // `Row` is the app's existing narrowing helper for raw query rows (the transform uses
        // it everywhere); a dashboard screen has no business inventing a second convention.
        $map = function (object $raw): array {
            $row = Row::cast($raw);

            return [
                'id' => Row::int($row, 'id'),
                'code' => Row::str($row, 'code'),
                'name' => Row::str($row, 'name'),
                'domain' => Row::nstr($row, 'domain'),
                'locales' => self::locales(Row::nstr($row, 'locales')),
                'default_locale' => Row::str($row, 'default_locale'),
                'currency' => Row::str($row, 'currency'),
                'is_active' => Row::bool($row, 'is_active'),
                'updated_at' => Row::nstr($row, 'updated_at'),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/Storefronts/Index', [
            'table' => $table->paginate($query, $map),
        ]);
    }

    public function edit(Storefront $storefront): Response
    {
        return Inertia::render('Manage/Storefronts/Edit', [
            'storefront' => [
                'id' => $storefront->id,
                'code' => $storefront->code,
                'name' => $storefront->name,
                'domain' => $storefront->domain,
                'locales' => $storefront->locales,
                'default_locale' => $storefront->default_locale,
                'currency' => $storefront->currency,
                'is_active' => $storefront->is_active,
                'money_rewards' => PromotionRules::moneyRewardsEnabled($storefront->id),
                /*
                 * ── The three sections that were SQL-only until 2026-10-13 (§4.1; backlog
                 *    SF-SETTINGS-SCREEN reverses SF-URL-SETTINGS) ──────────────────────────────
                 *
                 * `urls` — the four overrides; blank means "derived from the domain", and the
                 * derived value is shown beside each so the operator sees what the shop will use.
                 * `identity` — the five values every e-mail reads (W2). `mailbox` — the shop's
                 * SMTP row (W3/W4): the password is NEVER sent to the page, only `password_set`.
                 */
                'urls' => self::urlsOf($storefront),
                'derived_urls' => self::derivedUrls($storefront->id),
                'identity' => self::identityOf($storefront),
                'mailbox' => self::mailboxOf($storefront->id),
            ],
            /*
             * `value` is the stored locale code and never moves. Only `label` is read by a person,
             * and the screen renders it straight out of these props — which is why it has to come
             * off the seam here rather than in the component.
             */
            'locale_options' => [
                ['value' => 'ar', 'label' => ManageText::t('common.locale_arabic', 'العربية')],
                ['value' => 'en', 'label' => 'English'],   // i18n-exempt: the endonym is already the English word
            ],
        ]);
    }

    public function update(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'domain' => ['nullable', 'string', 'max:255'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string', 'size:2', Rule::in(['ar', 'en'])],
            'default_locale' => ['required', 'string', 'size:2', Rule::in(['ar', 'en'])],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['required', 'boolean'],
            'money_rewards' => ['required', 'boolean'],
        ] + StorefrontSettingsForm::rules());

        $locales = self::stringList($request->input('locales'));
        $defaultLocale = $request->string('default_locale')->toString();

        if (! in_array($defaultLocale, $locales, true)) {
            return back()->withErrors([
                'default_locale' => ManageText::t('storefronts.default_locale_not_enabled', 'اللغة الافتراضية يجب أن تكون من اللغات المفعّلة.'),
            ])->withInput();
        }

        // `code` is NOT editable: it is the storefront's identity in every URL, cache key and
        // compat payload (`/api/v2/{storefront}/…`), and the transform's deterministic-id guard
        // refuses a code/id disagreement. Renaming one is a migration, not a form field.
        $domain = strtolower(trim($request->string('domain')->toString()));

        /*
         * ── UP FRONT: what the readers would refuse at send time, refused here (§4.1) ──────────
         *
         * The sections arrive whole or not at all (`sometimes`): a caller that sends none of them
         * — the pre-2026-10-13 form, the tests that drive it — changes none of them.
         */
        $urls = $request->has('urls') ? Coerce::arr($request->input('urls')) : null;
        $identity = $request->has('identity') ? Coerce::arr($request->input('identity')) : null;
        $mailbox = $request->has('mailbox') ? Coerce::arr($request->input('mailbox')) : null;
        $problems = StorefrontSettingsForm::problems((int) $storefront->id, $domain, $urls, $identity, self::settingsOf($storefront))
            + ($mailbox === null ? [] : StorefrontSettingsForm::mailboxProblems($mailbox));
        if ($problems !== []) {
            $messages = [];
            foreach ($problems as $field => $kind) {
                $messages[$field] = self::problemText($kind);
            }

            return back()->withErrors($messages)->withInput();
        }

        // Read BEFORE the fill: the model is about to hold the new values, and this is the only
        // moment the old currency, the old domain and the old money switch still exist anywhere.
        $before = self::logFields($storefront->id);

        $storefront->fill([
            'name' => $request->string('name')->toString(),
            'domain' => $domain === '' ? null : $domain,
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'currency' => $request->string('currency')->upper()->toString(),
            'is_active' => $request->boolean('is_active'),
            /*
             * MERGED into whatever `settings` already holds, never replacing it. The column is a
             * general per-storefront bag and this screen owns exactly one key in it; writing the
             * whole object would silently drop anything another feature has put there.
             */
            'settings' => StorefrontSettingsForm::merge(
                self::withMoneyRewards($storefront->settings, $request->boolean('money_rewards')),
                $urls,
                $identity,
            ),
        ]);
        $storefront->save();

        if ($mailbox !== null) {
            $row = StorefrontMailbox::query()->firstOrNew(['storefront_id' => $storefront->id]);
            StorefrontSettingsForm::applyMailbox($row, $mailbox);
            $row->save();
            StorefrontMailer::forget((int) $storefront->id);
        }

        /*
         * ── The shop has to SEE the save (C-BUG-1, 2026-09-17) ──────────────────────────────
         *
         * Nothing here invalidated anything, and `ResolveStorefront` caches the whole storefront row
         * for ten minutes. Measured end to end before this fix:
         *
         *   • deactivate a storefront through this screen → the database says `is_active = 0` and a
         *     customer request immediately afterwards is still served 200. A deactivated shop kept
         *     trading for up to ten minutes.
         *   • rename it, change the currency EGP→USD, change the default locale → the database is
         *     right and the shop keeps serving the old name, currency and locale.
         *
         * The screen said "saved" and meant it — the row was written. What it could not say was that
         * the shop would not agree for another ten minutes, and nothing on it mentioned a delay.
         *
         * `forgetStorefront()` does both halves: it forgets the resolved row, which is the one with
         * no version in its key, and bumps the version so `meta` and the rest go with it. This is the
         * `StorefrontSettingsChanged` event `StorefrontCache::INVALIDATION_MAP` has always listed and
         * nothing ever fired.
         */
        $this->cache->forgetStorefront((int) $storefront->id, (string) $storefront->code);

        ActivityLog::record(
            'storefronts',
            $storefront->id,
            ActivityLog::UPDATED,
            $before,
            self::logFields($storefront->id),
            // The shop's NAME, which is what an operator calls it. `code` is the stable identity and
            // is on the row already as `subject_id`'s twin; the name is the word in the sentence.
            label: $storefront->name,
            storefrontId: $storefront->id,
        );

        return redirect()
            ->route('manage.storefronts.index')
            ->with('status', ManageText::t('storefronts.saved', 'تم حفظ إعدادات متجر :name.', ['name' => $storefront->name]));
    }

    /** @return array<string, mixed> */
    private static function settingsOf(Storefront $storefront): array
    {
        $raw = $storefront->settings;
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** @return array<string, string> the four overrides as stored, blank when not set */
    private static function urlsOf(Storefront $storefront): array
    {
        $urls = Coerce::arr(self::settingsOf($storefront)['urls'] ?? []);
        $out = [];
        foreach (StorefrontSettingsForm::URL_KEYS as $key) {
            $out[$key] = Coerce::str($urls[$key] ?? '');
        }

        return $out;
    }

    /**
     * What the shop USES for each URL today — the override, or the value derived from the domain —
     * shown beside the blank field so "leave it empty" is an informed choice. A storefront with no
     * host shows the refusal's words instead of a URL.
     *
     * @return array<string, string>
     */
    private static function derivedUrls(int $storefrontId): array
    {
        $out = [];
        foreach ([
            'frontend' => fn (): string => StorefrontUrls::frontend($storefrontId),
            'payment_return' => fn (): string => StorefrontUrls::paymentReturn($storefrontId),
            'api_host' => fn (): string => StorefrontUrls::apiHost($storefrontId),
            'asset_base' => fn (): string => StorefrontUrls::assetBase($storefrontId),
        ] as $key => $read) {
            try {
                $out[$key] = $read();
            } catch (StorefrontHasNoHost) {
                $out[$key] = ManageText::t('storefronts.derived_no_host', 'لا مضيف: لا نطاق ولا قيمة هنا');
            }
        }

        return $out;
    }

    /** @return array<string, string|bool> the five identity values as stored, plus whether each wordmark file exists */
    private static function identityOf(Storefront $storefront): array
    {
        $identity = Coerce::arr(self::settingsOf($storefront)['identity'] ?? []);
        $out = [];
        foreach (StorefrontSettingsForm::IDENTITY_KEYS as $key) {
            $out[$key] = Coerce::str($identity[$key] ?? '');
        }
        $out['mail_wordmark_exists'] = $out['mail_wordmark'] !== '' && is_file(public_path(ltrim(Coerce::str($out['mail_wordmark']), '/')));
        $out['mail_wordmark_1x_exists'] = $out['mail_wordmark_1x'] !== '' && is_file(public_path(ltrim(Coerce::str($out['mail_wordmark_1x']), '/')));

        return $out;
    }

    /**
     * The mailbox row for the page — every column but the password, plus `password_set` and the
     * fields a REAL send would still need (`missing`, empty when usable).
     *
     * @return array<string, mixed>
     */
    private static function mailboxOf(int $storefrontId): array
    {
        $row = StorefrontMailbox::query()->where('storefront_id', $storefrontId)->first();
        if ($row === null) {
            return [
                'exists' => false, 'is_enabled' => true, 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '',
                'from_address' => '', 'from_name' => '', 'daily_cap' => 800, 'transactional_reserve' => 200,
                'order_recipients' => '', 'password_set' => false, 'missing' => ['host', 'username', 'password', 'from_address', 'from_name'],
            ];
        }

        return [
            'exists' => true,
            'is_enabled' => $row->isEnabled(),
            'host' => $row->host(),
            'port' => $row->port(),
            'encryption' => $row->encryption(),
            'username' => $row->username(),
            'from_address' => $row->fromAddress(),
            'from_name' => $row->fromName(),
            'daily_cap' => $row->dailyCap(),
            'transactional_reserve' => $row->transactionalReserve(),
            'order_recipients' => implode("\n", $row->orderRecipients()),
            'password_set' => $row->credentialsSet(),              // a boolean — the only thing a page may say about it
            'missing' => $row->missing(needsTransport: true),
        ];
    }

    /** The up-front refusal, in the operator's language. */
    private static function problemText(string $kind): string
    {
        return match ($kind) {
            'no_host' => ManageText::t('storefronts.problem_no_host', 'بدون نطاق يجب تعبئة هذا الرابط، وإلا فلن يكون للمتجر مضيف (لا رجوع إلى متجر آخر).'),
            'url_host_only' => ManageText::t('storefronts.problem_url_host_only', 'رابط https:// بالمضيف فقط، بلا مسار ولا شرطة مائلة في النهاية.'),
            'url_https' => ManageText::t('storefronts.problem_url_https', 'رابط https:// صالح بلا شرطة مائلة في النهاية.'),
            'domain_shape' => ManageText::t('storefronts.problem_domain_shape', 'نطاق فقط، بأحرف صغيرة، بلا بروتوكول ولا مسار.'),
            'wordmark_missing' => ManageText::t('storefronts.problem_wordmark_missing', 'ملف PNG بهذا المسار غير موجود تحت public/.'),
            'whatsapp_digits' => ManageText::t('storefronts.problem_whatsapp_digits', 'أرقام دولية فقط، من 10 إلى 15 رقمًا، بلا + ولا مسافات (مثل 201551096234).'),
            'reserve_below_cap' => ManageText::t('storefronts.problem_reserve_below_cap', 'المحجوز للرسائل الأساسية يجب أن يكون أقل من الحد اليومي.'),
            'recipient_email' => ManageText::t('storefronts.problem_recipient_email', 'كل عنوان في القائمة يجب أن يكون بريدًا صالحًا.'),
            default => ManageText::t('storefronts.problem_required', 'هذه القيمة مطلوبة: بدونها لا تُرسَل رسائل هذا المتجر.'),
        };
    }

    /**
     * What a storefront is, for the log — the values this form can actually change.
     *
     * ── Why this screen is logged at all (2026-10-05) ───────────────────────────
     *
     * Every field on it changes what a CUSTOMER sees: `is_active` closes the shop, `currency`
     * changes the sign in front of every price, `default_locale` changes the language the site
     * opens in, `domain` moves it. One admin-only form, no trace of any of it — so "the shop was
     * down on Friday morning" and "prices showed in dollars" were questions with no author.
     *
     * `code` is not here because the form refuses to change it, and `created_at`/`updated_at`
     * because the log's own timestamp already says when.
     *
     * ── The `settings` JSON: ONE key, not the blob ──────────────────────────────
     *
     * `settings` is a general per-storefront bag and this screen owns exactly one key in it,
     * `promotions.money_rewards`. The whole column is deliberately NOT snapshotted:
     *
     *  • it is a diff nobody can read. `ActivityLog::diff()` renders a non-scalar as JSON truncated
     *    to 300 characters, so a grown bag would log two truncated dumps whose visible halves are
     *    identical — a row that says something changed and cannot say what.
     *  • it belongs to other features. A key written by a background job would surface in this
     *    screen's log, attributed to whoever last pressed Save here, which is the log asserting
     *    something that did not happen.
     *  • redaction is by FIELD NAME (`ActivityLog::REDACTED_FIELDS`), and `settings` matches none of
     *    them. A secret nested inside the bag one day would be copied into this table in the clear,
     *    which is the one thing the log promises never to do.
     *
     * So the one key this form writes is logged as its own flat boolean, where it reads as what it
     * is: a money switch that decides whether this shop shows promotion discounts at all.
     *
     * @return array<string, mixed>
     */
    private static function logFields(int $id): array
    {
        $raw = DB::table('storefronts')->where('id', $id)
            ->first(['name', 'domain', 'locales', 'default_locale', 'currency', 'is_active']);
        if (! is_object($raw)) {
            return [];
        }
        $row = Row::cast($raw);

        return [
            'name' => Row::str($row, 'name'),
            'domain' => Row::nstr($row, 'domain'),
            // Flattened to `ar, en` rather than left as raw JSON: the list is two items and a
            // reader should not have to parse `["ar","en"]` to see which language was dropped.
            'locales' => implode(', ', self::locales(Row::nstr($row, 'locales'))),
            'default_locale' => Row::str($row, 'default_locale'),
            'currency' => Row::str($row, 'currency'),
            'is_active' => Row::bool($row, 'is_active'),
            'money_rewards' => PromotionRules::moneyRewardsEnabled($id),
            // §4.1 (2026-10-13): the three sections the screen gained. Flat `section.key` names, so
            // "who changed the WhatsApp number" has an answer; the mailbox password is `password`,
            // which ActivityLog::REDACTED_FIELDS replaces with a marker on both sides.
            ...self::settingsLogFields($id),
        ];
    }

    /** @return array<string, mixed> */
    private static function settingsLogFields(int $id): array
    {
        $storefront = Storefront::query()->find($id);
        $out = [];
        if ($storefront instanceof Storefront) {
            foreach (self::urlsOf($storefront) as $key => $value) {
                $out['urls.'.$key] = $value;
            }
            foreach (StorefrontSettingsForm::IDENTITY_KEYS as $key) {
                $out['identity.'.$key] = self::identityOf($storefront)[$key];
            }
        }
        $mailbox = StorefrontMailbox::query()->where('storefront_id', $id)->first();
        if ($mailbox !== null) {
            $out['mailbox.is_enabled'] = $mailbox->isEnabled();
            $out['mailbox.host'] = $mailbox->host();
            $out['mailbox.port'] = $mailbox->port();
            $out['mailbox.encryption'] = $mailbox->encryption();
            $out['mailbox.username'] = $mailbox->username();
            $out['mailbox.from_address'] = $mailbox->fromAddress();
            $out['mailbox.from_name'] = $mailbox->fromName();
            $out['mailbox.daily_cap'] = $mailbox->dailyCap();
            $out['mailbox.transactional_reserve'] = $mailbox->transactionalReserve();
            $out['mailbox.order_recipients'] = implode(', ', $mailbox->orderRecipients());
            // The VALUE never enters the log (REDACTED_FIELDS matches `password`); the stored
            // ciphertext differs on every write, so "changed" means a password was typed.
            $out['password'] = $mailbox->credentialsSet() ? hash('sha256', $mailbox->password()) : '';
        }

        return $out;
    }

    /**
     * `settings` with `promotions.money_rewards` set, and everything else in it left alone.
     *
     * The value is written as a real boolean, because `PromotionRules::moneyRewardsEnabled()` reads
     * it with a strict `=== true`: a storefront whose setting arrived as the STRING "true" from a
     * hand-edit is treated as off, deliberately, and this is the writer that makes sure the screen
     * never produces that shape.
     *
     * @return array<string, mixed>
     */
    private static function withMoneyRewards(mixed $settings, bool $enabled): array
    {
        /** @var array<string, mixed> $out */
        $out = [];
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                $out[(string) $key] = $value;
            }
        }

        $promotions = $out['promotions'] ?? [];
        /** @var array<string, mixed> $bag */
        $bag = [];
        if (is_array($promotions)) {
            foreach ($promotions as $key => $value) {
                $bag[(string) $key] = $value;
            }
        }
        $bag[PromotionRules::MONEY_REWARDS_KEY] = $enabled;
        $out['promotions'] = $bag;

        return $out;
    }

    /**
     * `locales` is a JSON column on a MariaDB longtext, so a RAW row hands it back as a string
     * while the model casts it to an array. This screen reads raw rows for the list (no model
     * hydration for a table), hence the decode.
     *
     * @return list<string>
     */
    private static function locales(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return self::stringList(json_decode($value, true));
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return array_values(array_unique($out));
    }
}
