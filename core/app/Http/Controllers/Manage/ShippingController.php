<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Role;
use App\Domain\Shipping\ShippingCities;
use App\Support\Coerce;
use App\Support\FullReplace;
use App\Support\ManageText;
use App\Support\Table\TableExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shipping — the governorates and what delivery to each one costs (wave 4D).
 *
 * ── Read by everyone, written by administrators ─────────────────────────────────────────────
 *
 * The same split the orders export settled: the SCREEN is full visibility for both roles, because
 * "how much is delivery to Aswan?" is a question data-entry answer on the telephone all day, and
 * the ACT is restricted, because a shipping price is money. A wrong number here is charged to every
 * customer in that governorate until somebody reads the accounts.
 *
 * So `view-dashboard` reaches the list and `manage-shipping` is required for create, update and
 * delete — enforced on the ROUTE and again on the buttons, and proven by direct HTTP in
 * `ShippingScreenTest` rather than by a hidden control.
 *
 * ── A prepared list, not a paginated table ──────────────────────────────────────────────────
 *
 * 27 rows that grow by one every few years. `TableQuery` would add a page size, a sort whitelist
 * and a filter bar to a list that fits on a screen; the whole point of the screen is to see every
 * governorate and its price at once and spot the wrong one. It still exports through the shared
 * `TableExport`, so the file and its admin-only gate are the same as everywhere else.
 */
final class ShippingController
{
    public function __construct(private readonly ShippingCities $cities) {}

    public function index(Request $request): Response|StreamedResponse
    {
        $rows = $this->cities->all();

        $export = TableExport::wanted($request, 'shipping-cities', [
            'id' => ManageText::t('shipping.id', 'المعرّف'),
            'name_ar' => ManageText::t('shipping.governorate', 'المحافظة'),
            // Deliberately untranslated: this column holds the ENGLISH name of the governorate,
            // and its header names the column's language rather than the reader's.
            'name_en' => 'Governorate',
            'shipping_cost' => ManageText::t('shipping.cost', 'سعر الشحن'),
            'addresses' => ManageText::t('shipping.linked_addresses', 'عناوين مرتبطة'),
        ], $rows);

        if ($export !== null) {
            return $export;
        }

        return Inertia::render('Manage/Shipping/Index', [
            'cities' => $rows,
            'abilities' => [
                // The button asks for the same ability the route enforces, so the screen cannot
                // offer an action the server will refuse.
                'manage' => Gate::allows(Role::MANAGE_SHIPPING),
            ],
            /*
             * Said on the screen, not just in the code: the team should know that these four fields
             * are the whole of what the storefront reads, so nobody waits for a change that has
             * nowhere to land.
             */
            'notice' => ManageText::t('shipping.reads_four_fields', 'المتجر يقرأ من هذه الشاشة أربع قيم فقط: المعرّف، الاسم بالعربية، الاسم بالإنجليزية، وسعر الشحن. لا يوجد تفعيل/تعطيل ولا ترتيب ولا مناطق.'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate(ShippingCities::rules()));

        $this->cities->create($data);

        return back()->with('status', ManageText::t('shipping.city_added', 'تمت إضافة المحافظة.'));
    }

    public function update(Request $request, int $city): RedirectResponse
    {
        // A PUT replaces the row. Without this, a form that omitted `name_en` would blank it —
        // the shape `FullReplace` exists to catch, and the reason a colour once lost its hex.
        FullReplace::assert($request, ManageText::t('shipping.record', 'محافظة الشحن'), 'name_ar');

        $data = Coerce::arr($request->validate(ShippingCities::rules()));

        try {
            $this->cities->update($city, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name_ar' => $e->getMessage()]);
        }

        return back()->with('status', ManageText::t('shipping.cost_saved', 'تم حفظ سعر الشحن.'));
    }

    public function destroy(Request $request, int $city): RedirectResponse
    {
        $result = $this->cities->delete($city);

        return $result['deleted']
            ? back()->with('status', $result['reason'])
            : back()->withErrors(['delete' => $result['reason']]);
    }
}
