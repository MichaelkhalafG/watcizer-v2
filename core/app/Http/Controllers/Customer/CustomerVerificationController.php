<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerMail;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatAuth;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `auth/verify-email/{id}/{hash}` and `auth/resend-verification` (Phase 1, piece 4, 2026-09-21).
 *
 * ── Why the link is SIGNED and the legacy hash is not enough ────────────────────────────────
 *
 * The legacy check was `hash_equals(sha1($user->getEmailForVerification()), $hash)`. That proves
 * whoever built the URL knew the address — which anybody who knows a customer's e-mail can compute,
 * from a shell, in one line. The legacy route carried Laravel's `signed` middleware on top of it
 * for exactly that reason, and so does this one: the SIGNATURE is what makes the link unforgeable,
 * and the `sha1` is what stops a valid signature for one account being replayed against another
 * after an address change.
 *
 * Both checks are kept. Dropping either would be a downgrade, and the cheap one is not the real one.
 *
 * ── This route cannot carry the API key, and that is why it is outside that group ───────────
 *
 * It is reached by a person clicking a link in their mail client. There is no `Api-Code` header on
 * that request and there never can be — the legacy routes file says the same thing in the same
 * words ("Browser/email-hit endpoints … neither of which can send the Api-Code header"). What
 * stands in its place is the signature, which is a stronger check than a shared public key anyway.
 *
 * ── Verification gates NOTHING, deliberately ────────────────────────────────────────────────
 *
 * An unverified customer can shop, check out and pay, exactly as on the legacy storefront. Nothing
 * in this codebase reads `email_verified_at` to refuse anything. It is recorded because it is the
 * fact piece 6 needs — attaching a guest's past orders to a new account is safe only when the
 * address has been PROVEN — and making it a gate would be a product decision nobody has asked for.
 */
final class CustomerVerificationController extends Controller
{
    public function __construct(
        private readonly CustomerAccounts $accounts,
        private readonly CustomerMail $mail,
    ) {}

    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->find((int) $id);

        if ($user === null || ! hash_equals(sha1(Coerce::str($user->getAttribute('email'))), $hash)) {
            return response()->json(['error' => 'Invalid or expired verification link.'], 403);
        }

        // Idempotent: a link can sit in an inbox for days and be clicked twice, and the second
        // click must not move the timestamp — "verified since" is the useful half of the column.
        if (! $this->accounts->markVerified($user)) {
            return response()->json(['message' => 'Email already verified.']);
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /** Behind `compat.auth`: only the account itself may ask for another link. */
    public function resend(Request $request): JsonResponse
    {
        $user = User::query()->findOrFail(CompatAuth::id($request));

        if ($user->getAttribute('email_verified_at') !== null) {
            return response()->json(['message' => 'Email already verified.']);
        }

        /*
         * Throttled per ACCOUNT, because the cost of a resend is a real e-mail to a real inbox. An
         * unthrottled button here is a way to use this shop to post mail to somebody else — and the
         * somebody else is whoever owns the address on the account, who may be the person being
         * harassed rather than the person clicking.
         */
        $key = 'resend-verification|'.Coerce::int($user->getKey());
        $attempts = Coerce::int(config('customers.resend_verification.attempts'), 1);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json(['error' => "Please wait {$seconds} seconds before requesting another email."], 429);
        }

        RateLimiter::hit($key, Coerce::int(config('customers.resend_verification.decay_seconds'), 60));

        $this->mail->sendEmailVerification($user);

        // Reported as sent even when the transport refused, for the same reason `forgot` does: the
        // customer has no action to take either way, and `CustomerMail` has logged the failure.
        return response()->json(['message' => 'Verification email sent.']);
    }
}
