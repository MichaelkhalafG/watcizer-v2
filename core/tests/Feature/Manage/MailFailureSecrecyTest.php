<?php

use App\Domain\Notifications\MailFailure;
use App\Domain\Notifications\OrderMailer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\PaymentFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * A failed e-mail must not tell the room what the mail server's credentials are (🟠-2, 2026-09-17).
 *
 * ── The finding ──────────────────────────────────────────────────────────────────────────────
 *
 * `recordFailure()` stored `$e::class.': '.$e->getMessage()` and the order screen rendered it. A
 * Symfony `TransportException` for a rejected SMTP login says:
 *
 *     Failed to authenticate on SMTP server with username "orders@watchizereg.com" using 2 possible
 *     authenticators. Authenticator "LOGIN" returned "Expected response code 235 but got code 535".
 *     … from smtp.hostinger.com:465
 *
 * — the account's USERNAME and the relay's HOST:PORT, on a screen every `view-orders` holder can
 * open and in a column that goes into every backup. A relay outage is the ordinary way this
 * happens, so it needed no attacker at all.
 *
 * These tests drive the REAL exception text, because a test that invents a tidy message proves
 * nothing about the one Symfony actually raises.
 */

/** The exact shape Symfony Mailer raises when an SMTP login is refused. */
function smtpAuthException(): TransportException
{
    return new TransportException(
        'Failed to authenticate on SMTP server with username "orders@watchizereg.com" using 2 possible '
        .'authenticators. Authenticator "LOGIN" returned "Expected response code 235 but got code 535, '
        .'with message 535 5.7.8 Error: authentication failed". Stream: smtp.hostinger.com:465'
    );
}

it('never stores the SMTP username, the relay host or a port', function () {
    $stored = MailFailure::masked(smtpAuthException());

    /*
     * Each of these is a separate way the same secret escapes, so each is asserted separately —
     * a single "does not contain the whole sentence" would pass on a message that leaked half of it.
     */
    expect($stored)->not->toContain('orders@watchizereg.com')
        ->and($stored)->not->toContain('watchizereg')
        ->and($stored)->not->toContain('smtp.hostinger.com')
        ->and($stored)->not->toContain('hostinger')
        ->and($stored)->not->toContain(':465');

    /*
     * …and EVERY quoted run is the ellipsis, because quotes are where Symfony puts the username.
     *
     * Asserted by extracting the runs rather than with a "does not match" pattern: the obvious
     * negative regex `/"[^…"]+"/` passes for the wrong reason — it happily matches the ordinary
     * prose BETWEEN two already-masked quotes (`" using 2 possible authenticators. Authenticator "`)
     * and so would have failed on a correctly masked string. A test that reads as a security
     * assertion and is really matching prose is worse than no test.
     */
    preg_match_all('/"([^"]*)"/u', $stored, $quoted);
    expect(array_unique($quoted[1]))->toBe(['…'], 'a quoted value survived masking: '.$stored);

    /*
     * What DOES survive: the exception class and the words a developer needs to know where to look.
     * Masked, not dropped — "TransportException" alone is not a place to start from.
     */
    expect($stored)->toContain('TransportException')
        ->toContain('[auth]')
        ->toContain('Failed to authenticate on SMTP server');
});

it('classifies the three failures an operator acts on differently', function () {
    /*
     * The classification is the part anybody DOES anything with: refused credentials are a server
     * job and every e-mail in the shop is stopped; an unreachable relay is a network job and this
     * order is not special; a refused recipient is about this one address.
     *
     * Symfony raises the SAME TransportException for the first two, which is why this matches on
     * the message rather than the class.
     */
    expect(MailFailure::classify(smtpAuthException()))->toBe(MailFailure::AUTH)
        ->and(MailFailure::classify(new TransportException('Connection could not be established with host "smtp.hostinger.com:465"')))->toBe(MailFailure::CONNECT)
        ->and(MailFailure::classify(new TransportException('Expected response code 250 but got code "550", with message "550 5.1.1 recipient rejected"')))->toBe(MailFailure::REFUSED)
        // An honest "I do not know" rather than a guess: a wrong classification is worse than none.
        ->and(MailFailure::classify(new RuntimeException('array offset on null')))->toBe(MailFailure::UNKNOWN);
});

it('keeps last_error out of the order screen props entirely', function () {
    actingAs(Staff::admin());

    $orderId = PaymentFixture::order(total: 100.0);

    /*
     * A parked failure whose stored error is the WORST CASE: the raw, unmasked transport text, as a
     * row written before the masking existed would carry. Even then nothing of it may reach the
     * client — the screen is fed a classification, and the column itself is not in the payload.
     *
     * The condition is constructed here rather than hoped for (AGENTS §4).
     */
    DB::table('integration_outbox')->insert([
        'channel' => OrderMailer::CHANNEL,
        'aggregate_type' => 'orders',
        'aggregate_id' => $orderId,
        'event' => 'order.placed',
        'dedupe_key' => 'test-'.$orderId.'-secrecy',
        'payload' => json_encode(['kind' => 'confirmation', 'recipient' => 'someone@example.test'], JSON_THROW_ON_ERROR),
        'status' => 'failed',
        'attempts' => 3,
        'last_error' => 'TransportException: Failed to authenticate on SMTP server with username '
            .'"orders@watchizereg.com" … Stream: smtp.hostinger.com:465',
        'created_at' => now(),
        'processed_at' => now(),
    ]);

    $body = get("/manage/orders/{$orderId}")->assertOk()->getContent();

    expect($body)->not->toContain('orders@watchizereg.com')
        ->and($body)->not->toContain('smtp.hostinger.com')
        ->and($body)->not->toContain(':465')
        ->and($body)->not->toContain('last_error');

    /*
     * …the column itself never reaches the client, and the operator is told SOMETHING.
     *
     * `unknown` is the right answer for this row and not a shortcoming: it was written before the
     * classifier existed, so there is no classification stored in it to read. Saying "unknown" of a
     * failure it is showing is true; inventing `auth` by re-parsing the raw text would mean keeping
     * the raw text around to parse, which is the thing being removed.
     */
    $notifications = OrderMailer::forOrder($orderId);
    expect($notifications)->not->toBe([])
        ->and(array_key_exists('last_error', $notifications[0]))->toBeFalse()
        ->and(T::str($notifications[0]['error_kind'] ?? ''))->toBe(MailFailure::UNKNOWN)
        ->and(T::str($notifications[0]['error_label'] ?? ''))->not->toBe('');

    // A row written by the CURRENT path carries its classification and comes back as `auth`.
    DB::table('integration_outbox')
        ->where('aggregate_id', $orderId)
        ->update(['last_error' => MailFailure::masked(smtpAuthException())]);

    $classified = OrderMailer::forOrder($orderId);
    expect(T::str($classified[0]['error_kind'] ?? ''))->toBe(MailFailure::AUTH)
        // …and the label is the sentence about the RELAY, which is whose problem an auth failure is.
        ->and(T::str($classified[0]['error_label'] ?? ''))->toContain('خادم البريد');
});
