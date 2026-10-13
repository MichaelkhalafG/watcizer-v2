<?php

namespace Tests\Support;

use App\Domain\Notifications\StorefrontMailer;
use App\Models\Storefront\StorefrontMailbox;
use Illuminate\Support\Facades\DB;

/**
 * Every Feature test runs with both storefronts holding a MAILBOX (W3/W4, 2026-10-13).
 *
 * `storefront_mailboxes` is real configuration, written on each database by `mail:mailbox`
 * (production) — like the storefronts' identity, which {@see StorefrontIdentityFixture} fills the
 * same way. Until a row exists nothing can send: every mailer throws "has no usable mailbox". So
 * the suite inserts a row per storefront INSIDE each test's transaction (rolled back with it — the
 * dev database is never changed).
 *
 * The transport on the fixture row is `127.0.0.1:1` — a port nothing listens on — so a test that
 * switches `mail.default` to `smtp` to get a REAL refused connection (`OrderMailFailureTest`) gets
 * one from the row's transport, as production would from a dead relay. With the suite's default
 * `MAIL_MAILER=array` the transport is never opened and the row supplies only the envelope, the
 * budget and the recipients.
 *
 * A test that wants a storefront WITHOUT a mailbox deletes the row ({@see self::remove()}); one
 * that wants other recipients or another budget sets them ({@see self::recipients()},
 * {@see self::budget()}).
 */
final class StorefrontMailboxFixture
{
    /** @var array<string, array<string, mixed>> */
    public const VALUES = [
        'watchizer' => [
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'username' => 'suite@watchizer.test',
            'from_address' => 'no-reply@watchizer.test', 'from_name' => 'Watchizer',
            'daily_cap' => 800, 'transactional_reserve' => 200,
            'order_recipients' => ['orders@watchizer.test'],
        ],
        'brandfashion' => [
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'username' => 'suite@brandfashion.test',
            'from_address' => 'no-reply@brandfashion.test', 'from_name' => 'Brand Fashion',
            'daily_cap' => 800, 'transactional_reserve' => 200,
            'order_recipients' => ['orders@brandfashion.test'],
        ],
    ];

    public static function ensure(): void
    {
        StorefrontMailer::forget();                            // the per-request row cache outlives a test's transaction
        foreach (DB::table('storefronts')->whereIn('code', array_keys(self::VALUES))->get(['id', 'code']) as $row) {
            $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
            $code = is_string($row->code ?? null) ? $row->code : '';
            if ($id === 0 || StorefrontMailbox::query()->where('storefront_id', $id)->exists()) {
                continue;
            }
            StorefrontMailbox::query()->create(['storefront_id' => $id, 'is_enabled' => true, 'credentials' => ['password' => 'suite-only']] + self::VALUES[$code]);
        }
    }

    /** @param  list<string>  $addresses */
    public static function recipients(int $storefrontId, array $addresses): void
    {
        StorefrontMailbox::query()->where('storefront_id', $storefrontId)->update(['order_recipients' => json_encode($addresses)]);
        StorefrontMailer::forget($storefrontId);
    }

    public static function budget(int $storefrontId, int $dailyCap, int $transactionalReserve): void
    {
        StorefrontMailbox::query()->where('storefront_id', $storefrontId)->update(['daily_cap' => $dailyCap, 'transactional_reserve' => $transactionalReserve]);
        StorefrontMailer::forget($storefrontId);
    }

    /** Take a storefront's mailbox away for one test (rolled back with it). */
    public static function remove(int $storefrontId): void
    {
        StorefrontMailbox::query()->where('storefront_id', $storefrontId)->delete();
        StorefrontMailer::forget($storefrontId);
    }
}
