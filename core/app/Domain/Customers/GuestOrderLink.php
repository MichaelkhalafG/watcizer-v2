<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Activity\ActivityLog;
use App\Models\User;
use App\Support\Coerce;
use App\Support\Sql;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attaching a guest's past orders to the account they later created (Phase 1, piece 6, 2026-09-22).
 *
 * ── The decision, and the correction the decision needs ─────────────────────────────────────
 *
 * Developer decision, 2026-09-21, option (b) plus (c): *"attach past guest orders on registration
 * by VERIFIED email only; everything else attached by a human from the dashboard."*
 *
 * **The trigger is VERIFICATION, not registration**, and that is not a liberty taken with the
 * decision — it is the only coherent reading of it. At the moment somebody registers, their address
 * is not verified: the confirmation e-mail has just been sent and nobody has clicked anything.
 * Attaching "on registration by verified e-mail" would therefore attach nothing, ever, or would
 * attach on an unverified claim, which is the account-takeover shape {@see CustomerSocial} refuses
 * one branch over. So the attach hangs off the instant the address becomes PROVEN.
 *
 * ── Why the e-mail has to be proven at all ──────────────────────────────────────────────────
 *
 * A guest order carries the address the shopper typed at checkout. Nobody checked it. If an
 * unverified registration were enough, then registering `victim@example.com` and never confirming
 * it would hand over that person's order history — their delivery addresses, their telephone
 * number, what they bought and for how much. That is the same attack as branch 3 of the social
 * rules, reached through a different door, and it gets the same answer.
 *
 * ── ONE place where an address becomes verified ─────────────────────────────────────────────
 *
 * {@see CustomerAccounts::markVerified()} is the only code that stamps `email_verified_at`, and it
 * calls this. Everything that can verify an address goes through it — the e-mailed link, and a
 * social login whose provider already verified the address — INCLUDING a brand-new social account,
 * which is created unverified and then marked, at the cost of one extra UPDATE on a row that is
 * milliseconds old. That cost buys the property: there is no second path by which an address can
 * become verified without these orders being considered.
 *
 * ── What is NOT done, deliberately ──────────────────────────────────────────────────────────
 *
 * **The `guest_*` columns are not cleared.** They are the record of what the shopper actually typed
 * at checkout, and the courier rang the number in `guest_phone`. Erasing them to tidy up would
 * destroy evidence about an order in exchange for nothing.
 *
 * **Orders placed AFTER verification, while signed out, are not swept up.** The attach runs at the
 * transition, not on every sign-in, because running it on every social login would mean an
 * unindexed scan of `orders` on a hot path for a case that almost never happens. When it does
 * happen, it is what the dashboard action is for.
 */
final class GuestOrderLink
{
    /** The activity-log subject: the ORDER, because that is the row that changed. */
    public const SUBJECT = 'orders';

    /** How the link was made, recorded on every entry so the two paths stay distinguishable. */
    public const BY_VERIFIED_EMAIL = 'verified_email';

    public const BY_DASHBOARD = 'dashboard';

    /**
     * Attach every unclaimed guest order on this account's PROVEN address. Returns how many moved.
     *
     * @throws RuntimeException when the address is not verified — a caller reaching here with an
     *                          unverified account is a bug, and a silent no-op would hide it
     */
    public function onVerifiedEmail(User $user): int
    {
        if ($user->getAttribute('email_verified_at') === null) {
            throw new RuntimeException(
                'Refusing to attach guest orders to an account whose e-mail is not verified. '
                .'The address on a guest order is what somebody typed at checkout; attaching on an '
                .'unverified claim hands over a stranger\'s order history.'
            );
        }

        $email = CustomerAccounts::normaliseEmail(Coerce::str($user->getAttribute('email')));
        if ($email === '') {
            return 0;
        }

        return $this->attach($this->unclaimedByEmail($email), $user, self::BY_VERIFIED_EMAIL);
    }

    /**
     * Attach one guest GROUP to a named account, by hand, from the dashboard.
     *
     * The group is the same `g:` key the customer screen shows — orders sharing a telephone, or
     * failing that an address, or failing that the cart token. It is a HEURISTIC and the screen
     * says so; a human confirming it is exactly what this path is for, and why it is admin-only.
     *
     * @param  string  $guestKey  the `g:`-prefixed key from the customers screen
     */
    /**
     * @param  list<int>|null  $storefrontScope  the operator's storefronts; null = unscoped (admin)
     */
    public function attachGroup(string $guestKey, User $user, ?array $storefrontScope = null): int
    {
        $value = str_starts_with($guestKey, 'g:') ? substr($guestKey, 2) : '';
        if ($value === '') {
            return 0;
        }

        $query = DB::table('orders')
            ->whereNull('user_id')
            ->whereRaw(Sql::guestKey().' = ?', [$value]);

        /*
         * ── The SAME scope rule Customers::exists() applies (review: informational) ─────────
         *
         * The controller already checks the customer is in the operator's scope before getting
         * here. That is not the same question: a guest KEY is a telephone number, an address or a
         * cart token, and one shopper can have checked out as a guest on more than one storefront
         * with the same one. So a scoped operator who could legitimately see the guest on THEIR
         * storefront was moving that shopper's orders from every other storefront too — writes
         * outside their grant, made through a screen that had correctly authorised them for one
         * order and then acted on several.
         *
         * `[0]` for an empty grant, exactly as `Customers::exists()` does: an operator with a
         * grant naming no storefront must match nothing, and an unconstrained `whereIn` on `[]`
         * is a WHERE clause that matches everything in some drivers.
         */
        if ($storefrontScope !== null) {
            $query->whereIn('storefront_id', $storefrontScope === [] ? [0] : $storefrontScope);
        }

        $orders = $query->orderBy('id')->get(['id', 'order_number', 'guest_email']);

        return $this->attach($orders, $user, self::BY_DASHBOARD);
    }

    /**
     * Unclaimed orders whose checkout address matches, case- and whitespace-insensitively.
     *
     * `LOWER(TRIM(...))` rather than a plain comparison: the column's collation is already
     * case-insensitive, so `LOWER` is belt for the day somebody changes it, and `TRIM` is the half
     * that actually matters — a trailing space typed into a checkout field is invisible to the
     * person who typed it and fatal to an equality test.
     *
     * @return Collection<int, \stdClass>
     */
    private function unclaimedByEmail(string $email): Collection
    {
        return DB::table('orders')
            ->whereNull('user_id')
            ->whereNotNull('guest_email')
            ->whereRaw('LOWER(TRIM(guest_email)) = ?', [$email])
            ->orderBy('id')
            ->get(['id', 'order_number', 'guest_email']);
    }

    /**
     * Point the orders at the account, and record each one.
     *
     * ── Why one log entry per ORDER rather than one summary ─────────────────────────────────
     *
     * Somebody investigating a wrongly-attached order opens the ORDER, and the activity log is
     * keyed by subject. A summary row against the customer would be invisible from there, which is
     * the one place the question gets asked. The cost is one row per order moved; the count is
     * bounded by how many times one address checked out as a guest.
     *
     * The UPDATE keeps `whereNull('user_id')` in its own predicate, so two verifications racing —
     * or a dashboard attach landing at the same moment — cannot both claim the same order.
     *
     * @param  Collection<int, \stdClass>  $orders
     */
    private function attach(Collection $orders, User $user, string $how): int
    {
        if ($orders->isEmpty()) {
            return 0;
        }

        $userId = Coerce::int($user->getKey());
        $moved = 0;

        foreach ($orders as $order) {
            $id = Coerce::int($order->id ?? null);

            $claimed = DB::table('orders')->where('id', $id)->whereNull('user_id')->update(['user_id' => $userId]);
            if ($claimed === 0) {
                // Somebody else got there first. Not an error, and deliberately not logged as one.
                continue;
            }

            $moved++;

            /*
             * `ActivityLog::record()` reads `Auth::user()` itself, so the dashboard path names the
             * operator and the automatic one leaves it null — which reads correctly as "the system
             * did this" rather than pretending a person was involved. `how` is what tells the two
             * apart afterwards.
             */
            ActivityLog::record(
                self::SUBJECT,
                $id,
                ActivityLog::UPDATED,
                ['user_id' => null],
                ['user_id' => $userId, 'linked_by' => $how],
                label: Coerce::nstr($order->order_number ?? null),
            );
        }

        return $moved;
    }
}
