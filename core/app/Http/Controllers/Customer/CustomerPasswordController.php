<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerMail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * `auth/forgot-password` and `auth/reset-password` (Phase 1, piece 4, 2026-09-21).
 *
 * ── The broker is Laravel's; the TABLE and the final write are core's ───────────────────────
 *
 * Token generation, hashing, expiry and throttling all come from
 * `Illuminate\Auth\Passwords\DatabaseTokenRepository` rather than from anything written here. That
 * is deliberate: this is security-sensitive code where the failure modes are subtle, and
 * hand-rolling it to avoid a dependency the framework already ships would be the expensive kind of
 * cleverness.
 *
 * Two things ARE core's:
 *
 *   • **the table** — `core_password_resets`, not the legacy `password_reset_tokens`
 *     (`config/auth.php`, M1u). The legacy app is still running and still resetting passwords, and
 *     `DatabaseTokenRepository::create()` deletes every existing row for an address before
 *     inserting, so two brokers on one table would silently invalidate each other's links;
 *   • **the password write** — through `CustomerAccounts::resetPassword()`, which opens the
 *     `UserWrites` door with its own `customer.reset` reason. Laravel's documented callback would
 *     have written `users.password` directly and been refused by the model guard, which is the
 *     guard working rather than an obstacle to route around.
 *
 * ── Enumeration protection is the whole shape of `forgotPassword` ───────────────────────────
 *
 * It answers the SAME 200 and the SAME sentence whether the address is registered, unregistered, or
 * registered-but-throttled. That is the legacy behaviour and it is also the correct one: any branch
 * that answers differently turns this endpoint into a way to ask "does this person shop here?".
 * The consequence to accept knowingly: a customer who mistypes their address gets a cheerful
 * message and no e-mail, and there is no way to tell them apart from one who did not. The wording
 * says *"if that email is registered"* for exactly that reason.
 */
final class CustomerPasswordController extends Controller
{
    public function __construct(
        private readonly CustomerAccounts $accounts,
        private readonly CustomerMail $mail,
    ) {}

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email'], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $email = CustomerAccounts::normaliseEmail(Coerce::str($request->input('email')));

        try {
            /*
             * The callback is what makes this core's e-mail rather than the framework's
             * `ResetPassword` notification: core's model has no `sendPasswordResetNotification()`
             * and should not grow one, because every customer e-mail this application sends goes
             * out through `CustomerMail` and nowhere else.
             */
            Password::sendResetLink(['email' => $email], function (User $user, string $token): void {
                $this->mail->sendPasswordReset($user, $token);
            });
        } catch (\Throwable $e) {
            /*
             * Swallowed, as the legacy controller swallowed it. An exception here — a broker
             * misconfiguration, a database hiccup — must not become a different RESPONSE, because
             * a different response is exactly the signal the same-answer-every-time rule exists to
             * withhold. `CustomerMail` has already logged anything worth logging, by reference.
             */
            report($e);
        }

        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'min:'.CustomerAccounts::MIN_PASSWORD],
        ], [
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        $status = Password::reset([
            'email' => CustomerAccounts::normaliseEmail(Coerce::str($request->input('email'))),
            'password' => Coerce::str($request->input('password')),
            'password_confirmation' => Coerce::str($request->input('password_confirmation')),
            'token' => Coerce::str($request->input('token')),
        ], function (User $user, string $password): void {
            /*
             * The writer's own `ValidationException` is deliberately NOT caught: it surfaces as the
             * framework's 422 rather than as a broker status. It should be unreachable — the rule
             * above carries the same minimum — but the writer is the door, and a door that can
             * refuse must have its refusal go somewhere a caller can read.
             */
            $this->accounts->resetPassword($user, $password);
        });

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Your password has been reset.']);
        }

        /*
         * Everything else — an unknown address, a token that does not match, one that has expired,
         * one already spent — is 422 with the broker's own translated reason, which is what the
         * legacy endpoint answered. The reset PAGE is reached from a link, so unlike `forgot` there
         * is nothing to protect here: whoever is holding the link already knows the address.
         */
        return response()->json(['error' => __(Coerce::str($status))], 422);
    }
}
