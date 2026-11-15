<?php

namespace App\Http\Controllers\V2;

use App\Domain\Pickup\Pickup;
use App\Domain\Pickup\PickupStores;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v2/{storefront}/pickup — what the checkout page needs to offer "Pick up in store" (click & collect,
 * 2026-11-14): the shop's switch, its ACTIVE stores in order (the public fields only), how long a ready order is held,
 * and the two ready windows with the customer's sentence in both languages. The page picks the window from the cart's
 * lines (market when any line is sourced first) — the order itself records the window core decides.
 *
 * Read-only; held a minute in the browser (the dashboard's switch and stores reach new visits within that minute).
 * Under /api/v2/, so the API host's allow-list (runbook §4.1.1) is unchanged.
 */
class PickupController extends Controller
{
    public function show(StorefrontContext $ctx): JsonResponse
    {
        // read fresh (one primary-key read), never from the cached storefront row: switching pickup OFF must bite at once
        $settings = Pickup::settings($ctx->id());
        $stores = [];
        if ($settings['enabled']) {
            foreach (PickupStores::forStorefront($ctx->id(), activeOnly: true) as $s) {
                $stores[] = [
                    'id' => $s['id'],
                    'name' => ['en' => $s['name_en'], 'ar' => $s['name_ar']],
                    'address' => ['en' => $s['address_en'], 'ar' => $s['address_ar']],
                    'hours' => ['en' => $s['hours_en'], 'ar' => $s['hours_ar']],
                    'map_url' => $s['map_url'],
                    'phone' => $s['phone'],
                ];
            }
        }
        return response()->json(['data' => [
            // off, or on with no active store (a switch the server refuses to turn on, but a store can be switched off after)
            'enabled' => $settings['enabled'] && $stores !== [],
            'stores' => $stores,
            'hold_hours' => $settings['hold_hours'],
            'windows' => [
                'express' => self::window($settings['express_days'], Pickup::KIND_EXPRESS),
                'market' => self::window($settings['market_days'], Pickup::KIND_MARKET),
            ],
        ]], 200, ['Cache-Control' => 'private, max-age=60'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array{0: int, 1: int}  $days
     * @return array{kind: string, min: int, max: int, sentence: array{en: string, ar: string}}
     */
    private static function window(array $days, string $kind): array
    {
        return ['kind' => $kind, 'min' => $days[0], 'max' => $days[1], 'sentence' => Pickup::readySentence(['min' => $days[0], 'max' => $days[1]])];
    }
}
