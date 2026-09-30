<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Domain\Notifications\StockAlerts;
use App\Http\Controllers\Controller;
use App\Support\LegacyJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * "E-mail me when it's back" (2026-10-01).
 *
 *   POST /api/stock-alerts  {product_id, email?, locale?}
 *     A SIGNED-IN customer (a valid bearer token) is subscribed with their account's address — the
 *     one-tap button; `email` is ignored for them. A guest must send `email`. Answers
 *     `{status: subscribed|already|in_stock}`; 404 for a product this shop does not show; 422 for a
 *     guest without a valid address. Throttled per IP (`throttle:stock-alert`).
 *
 *   GET  /stock-alerts/stop/{token}  a page with ONE button — e-mail link scanners open links, so a
 *   POST /stock-alerts/stop/{token}  GET never cancels; the button's POST does.
 */
final class StockAlertController extends Controller
{
    public function __construct(private readonly CompatServices $compat, private readonly StockAlerts $alerts) {}

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'email' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ]);
        $userId = LegacyJwt::userId($request);
        $email = null;
        if ($userId !== null) {
            $account = DB::table('users')->where('id', $userId)->value('email');
            $email = is_string($account) && $account !== '' ? $account : null;
        }
        if ($email === null) {
            $typed = trim($request->string('email')->toString());
            if (filter_var($typed, FILTER_VALIDATE_EMAIL) === false) {
                return response()->json(['message' => 'invalid email', 'errors' => ['email' => ['invalid']]], 422);
            }
            $email = $typed;
        }

        $status = $this->alerts->subscribe(
            $this->compat->storefrontId,
            $request->integer('product_id'),
            $email,
            $userId,
            $request->string('locale', 'ar')->toString(),
        );
        if ($status === 'not_found') {
            return response()->json(['status' => $status], 404);
        }

        return response()->json(['status' => $status, 'signed_in' => $userId !== null]);
    }

    public function stopPage(string $token): Response
    {
        return response()->view('stock-alerts.stop', ['token' => $token, 'done' => null]);
    }

    public function stop(string $token): Response
    {
        return response()->view('stock-alerts.stop', ['token' => $token, 'done' => StockAlerts::cancel($token)]);
    }
}
