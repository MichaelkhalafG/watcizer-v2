<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Pickup\Pickup;
use App\Domain\Pickup\PickupStores;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Pick up in store" — the dashboard page of one storefront (click & collect, 2026-11-14).
 *
 * The team sets up the feature here: the ON/OFF switch (off by default; on only with an active store), the TERMS (how
 * long a ready order is held, the one extension, the ready windows in business days for our own stock and for items
 * sourced first, the open pay-at-store reservations a phone may hold) and the STORES (name, address and hours in both
 * languages, map link, phone, WhatsApp, active, order). The page shows the customer's ready sentence as it will read.
 *
 * Pay-at-store and its amount limit are a payment method on the Payments screen, like cash on delivery. Every save is
 * activity-logged; the storefront's caches are flushed so the checkout follows at once.
 */
final class PickupController
{
    public function __construct(private readonly StorefrontCache $cache) {}

    public function index(Storefront $storefront): Response
    {
        $sf = (int) $storefront->id;
        $settings = Pickup::settings($sf);
        $open = [];
        foreach (DB::table('order_pickups')->where('storefront_id', $sf)->whereIn('status', PickupStores::OPEN)
            ->groupBy('store_id')->selectRaw('store_id, COUNT(*) AS n')->get() as $raw) {
            $row = Row::cast($raw);
            $open[(string) Row::nint($row, 'store_id')] = Row::int($row, 'n');
        }

        return Inertia::render('Manage/Pickup/Index', [
            'storefront' => ['id' => $sf, 'code' => (string) $storefront->code, 'name' => (string) $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'enabled' => $settings['enabled'],
            'terms' => [
                'hold_hours' => $settings['hold_hours'],
                'extend_hours' => $settings['extend_hours'],
                'express_min' => $settings['express_days'][0],
                'express_max' => $settings['express_days'][1],
                'market_min' => $settings['market_days'][0],
                'market_max' => $settings['market_days'][1],
                'max_open_store_pay' => $settings['max_open_store_pay'],
            ],
            'sentences' => [
                'express' => Pickup::readySentence(['min' => $settings['express_days'][0], 'max' => $settings['express_days'][1]]),
                'market' => Pickup::readySentence(['min' => $settings['market_days'][0], 'max' => $settings['market_days'][1]]),
            ],
            'stores' => array_map(static fn (array $s): array => $s + ['open' => $open[(string) $s['id']] ?? 0], PickupStores::forStorefront($sf)),
            'limits' => ['hold' => Pickup::MAX_HOLD_HOURS, 'extend' => Pickup::MAX_EXTEND_HOURS, 'days' => Pickup::MAX_DAYS, 'open' => Pickup::MAX_OPEN_LIMIT],
        ]);
    }

    public function toggle(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');
        Pickup::setEnabled((int) $storefront->id, $enabled, (string) $storefront->name);
        $this->flush($storefront);

        return back()->with('status', $enabled
            ? ManageText::t('pickup.switched_on', 'تم تشغيل الاستلام من الفرع. يظهر الاختيار في صفحة إتمام الطلب الآن.')
            : ManageText::t('pickup.switched_off', 'تم إيقاف الاستلام من الفرع. الحجوزات الموجودة تبقى كما هي.'));
    }

    public function terms(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'hold_hours' => ['nullable'], 'extend_hours' => ['nullable'],
            'express_min' => ['nullable'], 'express_max' => ['nullable'],
            'market_min' => ['nullable'], 'market_max' => ['nullable'],
            'max_open_store_pay' => ['nullable'],
        ]));
        Pickup::setTerms((int) $storefront->id, $data, (string) $storefront->name);
        $this->flush($storefront);

        return back()->with('status', ManageText::t('pickup.terms_saved', 'تم حفظ الشروط. تسري على الحجوزات الجديدة.'));
    }

    public function stores(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'stores' => ['present', 'array'],
            'stores.*.id' => ['nullable', 'integer'],
            'stores.*.name_en' => ['nullable', 'string'], 'stores.*.name_ar' => ['nullable', 'string'],
            'stores.*.address_en' => ['nullable', 'string'], 'stores.*.address_ar' => ['nullable', 'string'],
            'stores.*.hours_en' => ['nullable', 'string'], 'stores.*.hours_ar' => ['nullable', 'string'],
            'stores.*.map_url' => ['nullable', 'string'], 'stores.*.phone' => ['nullable', 'string'], 'stores.*.whatsapp' => ['nullable', 'string'],
            'stores.*.is_active' => ['nullable', 'boolean'],
        ]));
        $rows = [];
        foreach (Coerce::arr($data['stores'] ?? null) as $row) {
            $row = Coerce::arr($row);
            $row['is_active'] = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $rows[] = $row;
        }
        PickupStores::save((int) $storefront->id, $rows, Pickup::enabled((int) $storefront->id));
        $this->flush($storefront);

        return back()->with('status', ManageText::t('pickup.stores_saved', 'تم حفظ الفروع.'));
    }

    private function flush(Storefront $storefront): void
    {
        // the resolved storefront row (its settings) is cached ten minutes; the checkout reads the stores through it
        $this->cache->forgetStorefront((int) $storefront->id, (string) $storefront->code);
        $this->cache->flush((int) $storefront->id);
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (DB::table('storefronts')->orderBy('id')->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $out[] = ['value' => (string) Row::int($row, 'id'), 'label' => Row::str($row, 'name')];
        }

        return $out;
    }
}
