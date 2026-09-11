<?php

namespace App\Http\Controllers\Manage;

use App\Models\Storefront\Storefront;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
    public function index(Request $request): Response
    {
        $table = TableQuery::for($request)
            ->sortable(['id', 'code', 'name', 'is_active', 'created_at'], default: 'id')
            ->searchable(['code', 'name', 'domain'])
            ->filterable(['is_active' => ['0', '1']])
            ->perPage(default: 25, max: 100);

        $query = DB::table('storefronts')->select(['id', 'code', 'name', 'domain', 'locales', 'default_locale', 'currency', 'is_active', 'updated_at']);

        return Inertia::render('Manage/Storefronts/Index', [
            // `Row` is the app's existing narrowing helper for raw query rows (the transform uses
            // it everywhere); a dashboard screen has no business inventing a second convention.
            'table' => $table->paginate($query, function (object $raw): array {
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
            }),
            // The finding above, on the screen. It is a fact about the deployment procedure, so it
            // belongs where the person editing can read it.
            'rebuild_warning' => 'جدول المتاجر محميّ من إعادة البناء (AGENTS §2.20) فلا تُفقد هذه الإعدادات. لكن قبل ليلة التحويل '
                .'يبقى النظام القديم هو مصدر البيانات، وأي تعديل في شاشات الكتالوج يُستبدل بما فيه.',
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
            ],
            'locale_options' => [
                ['value' => 'ar', 'label' => 'العربية'],
                ['value' => 'en', 'label' => 'English'],
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
        ]);

        $locales = self::stringList($request->input('locales'));
        $defaultLocale = $request->string('default_locale')->toString();

        if (! in_array($defaultLocale, $locales, true)) {
            return back()->withErrors(['default_locale' => 'اللغة الافتراضية يجب أن تكون من اللغات المفعّلة.'])->withInput();
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
        ]);
        $storefront->save();

        return redirect()
            ->route('manage.storefronts.index')
            ->with('status', "تم حفظ إعدادات متجر {$storefront->name}.");
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
