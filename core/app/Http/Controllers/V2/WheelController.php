<?php

namespace App\Http\Controllers\V2;

use App\Domain\Wheel\Wheel;
use App\Domain\Wheel\WheelPrizes;
use App\Domain\Wheel\WheelRefused;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\Coerce;
use App\Support\LegacyJwt;
use App\Support\Val;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The wheel on `/api/v2/{storefront}/wheel` (2026-11-07; the team's prizes since 2026-11-08).
 *
 *   GET  wheel            is it on, the terms (validity, minimum) and the segments — colours and labels, never the odds
 *   POST wheel/spin       spin for a phone — the PRIZE IS DECIDED HERE; the page only animates to the segment returned
 *   GET  wheel/spin       the spin a phone already made (so a returning visitor sees their code on any device)
 *
 * Never cached: `private, max-age=0` on the spin answers (one shopper's code must never be served to another); the
 * segments may be held for a minute. Both spin routes are throttled per address (AppServiceProvider `wheel-spin`), and
 * a device may spin once per campaign (Wheel). The phone is answered MASKED everywhere but in the spin's own answer to
 * the person who typed it.
 */
class WheelController extends Controller
{
    public function show(StorefrontContext $ctx): JsonResponse
    {
        $settings = Wheel::settingsFrom($ctx->storefront->getAttribute('settings'));

        return $this->json([
            'enabled' => $settings['enabled'],
            'campaign' => $settings['campaign'],
            'ttl_hours' => $settings['ttl_hours'],
            'min_order' => $settings['min_order'],
            'segments' => WheelPrizes::segments($ctx->id()),
        ], 200, 'private, max-age=60');
    }

    public function spin(Request $request, StorefrontContext $ctx): JsonResponse
    {
        try {
            $data = Validator::make($request->all(), [
                'phone' => 'required|string|min:7|max:24',
                'device' => 'nullable|string|max:64',
                'locale' => 'nullable|string|in:ar,en',
                'utm' => 'nullable|array',
                'utm.source' => 'nullable|string|max:120',
                'utm.medium' => 'nullable|string|max:120',
                'utm.campaign' => 'nullable|string|max:120',
                'utm.content' => 'nullable|string|max:120',
            ])->validate();
        } catch (ValidationException) {
            return $this->json(['error' => WheelRefused::INVALID_PHONE, 'messages' => (new WheelRefused(WheelRefused::INVALID_PHONE))->messages()], 422);
        }

        $utm = Val::arr($data, 'utm');
        try {
            $result = Wheel::spin(
                $ctx->id(),
                Val::nstr($data, 'phone'),
                Val::nstr($data, 'device'),
                $request->ip(),
                [
                    'source' => Coerce::nstr($utm['source'] ?? null),
                    'medium' => Coerce::nstr($utm['medium'] ?? null),
                    'campaign' => Coerce::nstr($utm['campaign'] ?? null),
                    'content' => Coerce::nstr($utm['content'] ?? null),
                ],
                Val::nstr($data, 'locale') ?? $ctx->locale,
            );
        } catch (WheelRefused $e) {
            return $this->json(['error' => $e->reason, 'messages' => $e->messages()], $e->status());
        } catch (ValidationException $e) {
            // the prize rule could not be created — money rewards are OFF on this storefront (Storefronts → Money rewards):
            // the shopper sees "ended"; the operator finds the reason in the log (no phone in it — it is personal data)
            Log::warning('wheel: a spin was refused because the prize rule could not be created', ['storefront' => $ctx->id(), 'errors' => $e->errors()]);

            return $this->json(['error' => WheelRefused::WHEEL_OFF, 'messages' => (new WheelRefused(WheelRefused::WHEEL_OFF))->messages()], 410);
        }

        return $this->json(['status' => $result['status'], 'spin' => Wheel::present($result['spin'], null, $result['index'])]);
    }

    public function lookup(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $phone = Wheel::normalizePhone(Coerce::nstr($request->query('phone')));
        if ($phone === null) {
            return $this->json(['error' => WheelRefused::INVALID_PHONE, 'messages' => (new WheelRefused(WheelRefused::INVALID_PHONE))->messages()], 422);
        }
        $spin = Wheel::spinByPhone($ctx->id(), $phone);
        if ($spin === null) {
            return $this->json(['error' => 'none'], 404);
        }

        return $this->json(['spin' => Wheel::present($spin)]);
    }

    /**
     * POST wheel/claim `{phone}` with the customer's Bearer token (2026-11-09 evening; every prize since 2026-11-10):
     * the signed-in customer saves this round's spin of that phone to their account — an invite is activated by it.
     */
    public function claim(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $userId = LegacyJwt::userId($request);
        if ($userId === null) {
            return $this->json(['error' => 'unauthenticated'], 401);
        }
        $phone = Wheel::normalizePhone(Coerce::nstr($request->input('phone')));
        if ($phone === null) {
            return $this->json(['error' => WheelRefused::INVALID_PHONE, 'messages' => (new WheelRefused(WheelRefused::INVALID_PHONE))->messages()], 422);
        }
        $result = Wheel::claim($ctx->id(), $phone, $userId);
        if ($result['status'] === 'none' || $result['spin'] === null) {
            return $this->json(['error' => 'none'], 404);
        }
        if ($result['status'] === 'taken') {
            return $this->json(['error' => 'spin_taken', 'messages' => [
                'en' => 'This spin was saved to another account. Sign in with that account to see it.', // i18n-exempt: STOREFRONT — the shopper's sentence, both languages here
                'ar' => 'هذه اللفّة محفوظة في حساب آخر. سجّل الدخول بذلك الحساب لتراها.', // i18n-exempt: STOREFRONT — the shopper's sentence, both languages here
            ]], 409);
        }

        return $this->json(['status' => $result['status'], 'spin' => Wheel::present($result['spin'])]);
    }

    /** GET wheel/mine with the customer's Bearer token: every spin saved to the account (codes, gifts, invites with their rewards), newest first, for the account page. */
    public function mine(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $userId = LegacyJwt::userId($request);
        if ($userId === null) {
            return $this->json(['error' => 'unauthenticated'], 401);
        }
        $spins = [];
        foreach (Wheel::spinsOfUser($ctx->id(), $userId) as $spin) {
            $spins[] = Wheel::present($spin);
        }

        return $this->json(['spins' => $spins]);
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data, int $status = 200, string $cache = 'private, max-age=0, no-store'): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['Cache-Control' => $cache], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
