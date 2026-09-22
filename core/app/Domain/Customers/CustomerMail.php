<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Mail\CustomerEmailVerification;
use App\Mail\CustomerPasswordReset;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The two account e-mails, and the one place either is sent (Phase 1, piece 4, 2026-09-21).
 *
 * ── Three properties, each of which is a way this goes wrong if it is missing ───────────────
 *
 * **1. A mail failure never fails the action.** The legacy controller wrapped
 * `sendEmailVerificationNotification()` in a try/catch with a `Log::warning` for exactly this
 * reason, and `forgotPassword()` swallowed too. A relay that refuses must not turn a successful
 * registration into a 500 — the account exists, the customer is signed in, and a verification
 * e-mail that did not arrive is a button away from being resent.
 *
 * **2. The LINK never reaches a log.** A reset URL is a bearer credential for sixty minutes: put
 * it in a log line and anybody with the log file owns the account. So the failure path records the
 * user id, the kind of e-mail and the exception's message — and nothing else. Not the URL, not the
 * token, not the mailable. This is the same rule AGENTS §3 states for helper scripts, one layer in.
 *
 * **3. There is ONE sender.** Both e-mails carry a credential in a URL, and the discipline above
 * only holds if it is written once. A second `Mail::to(...)->send(new CustomerPasswordReset(...))`
 * anywhere else is a second place that can log the wrong thing, and `AccountMailTest` greps for it.
 *
 * ── What this class deliberately does NOT use ───────────────────────────────────────────────
 *
 * `OrderMailer` and its `integration_outbox` row. That machinery exists because an order
 * confirmation is a RECORD — prerequisite (a), 2026-09-13: a pending row is a message somebody is
 * waiting for and a sent row is proof it went out. Neither is true here. A reset link is valid for
 * an hour and must not survive in a durable table where it would be a credential at rest; a
 * verification link is re-requestable at will. Retrying either an hour later would post a dead
 * link, so the outbox would turn a clean failure into a confusing one.
 */
final class CustomerMail
{
    public const KIND_RESET = 'password_reset';

    public const KIND_VERIFY = 'email_verification';

    /** The signed-route name the verification link points at, and the only place it is named. */
    public const VERIFY_ROUTE = 'customer.verify-email';

    /** How long a verification link stays usable. Longer than a reset: it proves, it does not open. */
    public const VERIFY_HOURS = 48;

    /**
     * Send a reset link. Returns false when the mail did not go out, so a caller can say so —
     * `forgotPassword` deliberately does not, because telling the caller would reveal whether the
     * address is registered.
     */
    public function sendPasswordReset(User $user, string $token): bool
    {
        $url = rtrim(config()->string('customers.storefront_url'), '/')
            .'/reset-password?token='.urlencode($token)
            .'&email='.urlencode(Coerce::str($user->getAttribute('email')));

        return $this->send(
            $user,
            self::KIND_RESET,
            new CustomerPasswordReset($url, self::greetingName($user), Coerce::int(config('auth.passwords.users.expire'), 60)),
        );
    }

    /**
     * Send a verification link.
     *
     * The URL is a TEMPORARY SIGNED route, so the `{id}/{hash}` pair cannot be walked by hand: the
     * legacy check was `sha1($email)`, which anybody who knows a customer's address can compute.
     * The signature is what makes the link unforgeable, and it is computed from this application's
     * `APP_KEY` — so a link the legacy host mailed can never be verified here, which is correct
     * rather than a compatibility problem (only one of the two is any given customer's shop).
     */
    public function sendEmailVerification(User $user): bool
    {
        $email = Coerce::str($user->getAttribute('email'));

        $url = URL::temporarySignedRoute(self::VERIFY_ROUTE, now()->addHours(self::VERIFY_HOURS), [
            'id' => Coerce::int($user->getKey()),
            'hash' => sha1($email),
        ]);

        return $this->send($user, self::KIND_VERIFY, new CustomerEmailVerification($url, self::greetingName($user)));
    }

    /**
     * The one send, with the one failure policy.
     *
     * @param  Mailable  $mailable
     */
    private function send(User $user, string $kind, $mailable): bool
    {
        $email = Coerce::str($user->getAttribute('email'));
        if ($email === '') {
            return false;
        }

        try {
            Mail::to($email)->send($mailable);

            return true;
        } catch (Throwable $e) {
            /*
             * By REFERENCE. The id says who, the kind says which e-mail, and the message says what
             * the transport complained about — enough to diagnose a broken relay. The URL, the
             * token and the address are all absent on purpose: a log file is not a place to keep a
             * credential, and "the reset mail failed" is not worth learning by leaking one.
             */
            Log::warning('customer account e-mail failed', [
                'user_id' => Coerce::int($user->getKey()),
                'kind' => $kind,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** A name to greet with — the first name, or the part of the address before the `@`. */
    private static function greetingName(User $user): string
    {
        $first = trim(Coerce::str($user->getAttribute('first_name')));
        if ($first !== '') {
            return $first;
        }

        return explode('@', Coerce::str($user->getAttribute('email')))[0];
    }
}
