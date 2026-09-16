<?php

namespace App\Domain\Orders;

use App\Domain\Access\Preferences;
use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * How many orders this operator has not looked at (wave 4D).
 *
 * ── The cheap version, deliberately ─────────────────────────────────────────────────────────
 *
 * A count beside "الطلبات" in the sidebar that refreshes when the operator navigates. No polling,
 * no websocket, no push. The shop's queue is worked by people who are already clicking around the
 * dashboard all day, and the difference between "you find out within a minute" and "you find out
 * on your next click" is not worth a realtime stack on shared hosting — §5.2.1 keeps Redis behind
 * a VPS move for the same reason.
 *
 * ── It runs on EVERY dashboard render, so it is one indexed query ───────────────────────────
 *
 * This is shared Inertia data: the shell asks for it on every page, not just the queue. That makes
 * cost the design constraint. It is a single `COUNT(*)` over `orders` with `created_at >` and an
 * optional `storefront_id IN`, both indexed — `orders_created_at_index` was added by M1n for the
 * queue's own sort and this rides it.
 *
 * It is also skipped entirely for anybody who cannot see orders, which is most of the cost on a
 * data-entry-heavy day: no ability, no query.
 *
 * ── "New" means UNSEEN BY THIS PERSON ───────────────────────────────────────────────────────
 *
 * Per user, not global, because two people work the same queue and a shared stamp would let the
 * first to look clear the badge for everybody else. A first-ever visit counts ZERO rather than
 * every order ever placed: a badge showing 75 the first time somebody logs in is noise, and a
 * badge people learn to ignore has cost more than it gave.
 */
final class NewOrders
{
    public function __construct(private readonly Roles $roles) {}

    /**
     * Orders placed since this operator last opened the queue.
     *
     * Returns 0 for anybody who may not see orders, and 0 for a first visit.
     */
    public function countFor(?User $user): int
    {
        if (! $user instanceof User || ! Gate::allows(Role::VIEW_ORDERS)) {
            return 0;
        }

        $seenAt = Preferences::ordersSeenAt($user);
        if ($seenAt === null) {
            // Never looked. Counting everything would be true and useless; the stamp is set the
            // first time they open the queue and the badge starts meaning something from then.
            return 0;
        }

        /*
         * STRICTLY after. `orders_seen_at` and `orders.created_at` are both second-granularity, so
         * an order created in the SAME second as the operator's visit falls outside this window.
         *
         * That is the deliberate side of a one-second trade. `>=` would instead re-count an order
         * the operator has just looked at, producing a badge that does not clear — and a badge that
         * will not go away is how people learn to stop reading it. Missing the single order that
         * arrives in the same second as somebody opens the queue costs one refresh; the other
         * failure costs the feature.
         */
        $query = DB::table('orders')->where('created_at', '>', $seenAt);

        /*
         * A scoped grant counts only its own storefronts' orders (§2.18) — the same narrowing the
         * queue itself applies. Without it the badge would promise rows the screen then refuses to
         * show, which is worse than no badge: it sends somebody looking for an order they are not
         * allowed to see.
         */
        $scope = $this->roles->storefrontScope($user);
        if ($scope !== null) {
            $query->whereIn('storefront_id', $scope === [] ? [0] : $scope);
        }

        return Coerce::int($query->count());
    }
}
