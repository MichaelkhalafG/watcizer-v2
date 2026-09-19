<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Promotions\PromotionRules;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
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
                'code' => ManageText::t('products.code', 'الكود'),
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
        ]);

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
        $domain = $request->string('domain')->toString();

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
            'settings' => self::withMoneyRewards($storefront->settings, $request->boolean('money_rewards')),
        ]);
        $storefront->save();

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

        return redirect()
            ->route('manage.storefronts.index')
            ->with('status', ManageText::t('storefronts.saved', 'تم حفظ إعدادات متجر :name.', ['name' => $storefront->name]));
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
