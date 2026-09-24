<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Catalog\PreSwitch;
use App\Models\User;
use RuntimeException;

/**
 * The ONE lock on the shared `users` table, and the declared list of what may open it.
 *
 * ── Why this exists, and why it is not `DashboardAccounts` any more ─────────────────────────
 *
 * Until 2026-09-20 core never wrote this table and the rule was absolute. The reason was specific:
 * the legacy application serialised every `users` column with `toArray()` into the PROXIED
 * `login` / `register` / `me` responses the live storefront consumed, so any column core touched
 * could surface in that JSON. On the standalone eleganceeg.com deployment those routes are closed,
 * and {@see DashboardAccounts} was given a two-operation permission behind a depth-counted door.
 *
 * Phase 1 of the storefront migration moves CUSTOMER authentication into core. That is the second
 * writer the `DashboardAccounts` docblock said would void its permission — so the permission is
 * re-granted here, wider, and in a shape that makes the width READABLE:
 *
 *   • the lock lives in one place and nothing else may open it;
 *   • every operation that may open it is DECLARED in {@see self::REASONS}, with what it writes;
 *   • a reason that is not declared is refused at the door rather than at review.
 *
 * So "may core write `users`?" is answered by reading one constant, and widening the permission is
 * an edit to that constant — which is exactly the kind of change a diff shows and a reviewer stops
 * at. The alternative, a second door beside the first, gives two lists that drift.
 *
 * ── What is NOT permitted, still, and absolutely ────────────────────────────────────────────
 *
 * DELETING a row. There is no reason for it and no door: a person who should lose dashboard access
 * has their `core_user_roles` grant revoked, and a customer who asks to be forgotten is a
 * conversation with the client before it is a DELETE. {@see User::booted()} refuses it
 * with no escape hatch at all.
 *
 * `remember_token`, `last_login_at` and `last_reengagement_at` — the three framework defaults wave
 * 4A turned off at the model. They stay off. Nothing in {@see self::REASONS} names them, and the
 * model's three overrides make the first unreachable regardless.
 *
 * ── A mechanism, not a promise ──────────────────────────────────────────────────────────────
 *
 * {@see User::booted()} refuses every save that is not inside {@see self::open()}, so
 * a stray `$user->save()` anywhere in the application is a loud exception in a test rather than a
 * silent row change in a table holding every customer account. The same shape as
 * {@see PreSwitch::allowing()}.
 */
final class UserWrites
{
    /**
     * Every operation permitted to write `users`, and the columns it writes.
     *
     * The value is not decoration: `UserWritesTest` asserts that every key here has a call site and
     * that every call site names a key here, so the list cannot quietly outlive the feature that
     * needed it or be routed around by a caller inventing a string.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        // ── the dashboard (AGENTS §2.18, 2026-09-20) ──────────────────────────────────────
        'dashboard.create' => 'a new dashboard account: first_name, last_name, email, password, type',
        'dashboard.password' => 'an operator changing their own password: password',

        /*
         * ── the customer's own account (storefront Phase 1, 2026-09-21) ───────────────────
         *
         * Four operations, all of them the customer acting on themselves through the storefront.
         * `last_login_at` is named by NONE of them: the legacy app wrote it on every sign-in for a
         * re-engagement campaign, AGENTS §3 forbids core the column, and after Phase 2 it simply
         * stops advancing. "Last seen" needs a core-owned table if it is ever wanted again.
         */
        'customer.register' => 'a new shopping account: first_name, last_name, email, password, phone_number, image, type',
        'customer.profile' => 'a customer editing their own details: first_name, last_name, phone_number, image',
        'customer.password' => 'a customer setting or changing their own password: password',
        'customer.avatar' => 'a customer removing their own photo: image',

        /*
         * Piece 4. Both are the customer acting on themselves, and both are reached WITHOUT a
         * session — which is exactly why each is its own reason rather than being folded into
         * `customer.password`: an audit that cannot tell "changed it while signed in" from
         * "changed it holding an e-mailed token" cannot answer the only question anybody asks
         * after an account is taken over.
         */
        'customer.reset' => 'a customer completing a password reset from an e-mailed token: password',
        'customer.verified' => 'a customer proving they own their e-mail address: email_verified_at',

        /*
         * Piece 5. Its own reason rather than `customer.register`, because the row it creates is
         * shaped differently and the difference is the interesting one: `password` is NULL — the
         * provider is the credential. An audit that could not tell the two apart could not answer
         * "how did this password-less account come to exist".
         *
         * `email_verified_at` is NOT written here, deliberately: a social account is created
         * unverified and then marked through `customer.verified`, so that there is exactly ONE
         * place an address becomes verified and exactly one place guest-order attachment can hang
         * off (piece 6).
         */
        'customer.social_register' => 'a social login creating a password-less account: first_name, last_name, email, type',
    ];

    /**
     * The reasons currently open, innermost last.
     *
     * A stack rather than a counter, for two reasons the counter could not give: a nested call must
     * not re-open the door on its way out (that is what the counter did), AND a refusal or a test
     * can say WHICH operation was open when something unexpected happened.
     *
     * @var list<string>
     */
    private static array $open = [];

    /**
     * Run `$work` with the `users` write-guard lifted for one declared reason.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function open(string $reason, callable $work): mixed
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw new RuntimeException(
                "[{$reason}] is not a declared reason to write the `users` table. The permitted "
                .'operations are listed in App\Domain\Access\UserWrites::REASONS; adding one is a '
                .'decision about the rule (AGENTS §2.18), not a string a caller may invent.'
            );
        }

        self::$open[] = $reason;

        try {
            return $work();
        } finally {
            array_pop(self::$open);
        }
    }

    /** Is a `users` write permitted at this instant? Asked by {@see User::booted()}. */
    public static function permitted(): bool
    {
        return self::$open !== [];
    }

    /** The innermost reason currently open, or null — for a refusal message and for tests. */
    public static function reason(): ?string
    {
        $last = end(self::$open);

        return $last === false ? null : $last;
    }

    /**
     * The refusal {@see User::booted()} throws, as its own method so the message is
     * written once.
     *
     * Not a `ValidationException`: this is never something an operator did. It is a developer
     * writing `users` from somewhere that did not open the door, and it should read like a bug.
     */
    public static function refuse(string $operation): never
    {
        $reasons = implode(', ', array_keys(self::REASONS));

        throw new RuntimeException(
            "core may not {$operation} the legacy `users` table from here. The permitted operations "
            ."are [{$reasons}], each opened through App\\Domain\\Access\\UserWrites::open(). See "
            .'AGENTS §2.18 — if you believe another is needed, that is a decision about the rule, '
            .'not a call to open() with a new string.'
        );
    }
}
