<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Orders\OrderCustomer;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meta Conversions API — the server-side `Purchase` for a CARD order (B2, 2026-09-29).
 *
 * ── Why here ──────────────────────────────────────────────────────────────────────────────────
 * A card shopper leaves for Paymob and the browser is never told the payment cleared (runbook
 * §11.1), so the storefront fires no `Purchase` for card orders — deliberately: one fired at the
 * hand-off would count every declined card as a sale. The payment callback is the only place that
 * KNOWS the money arrived, so the event is enqueued there, beside the order e-mail.
 *
 * ── How it is sent — the order e-mail's outbox discipline, on its own channel ───────────────────
 *  - ENQUEUED inside the payment transaction (`purchase()`), so the obligation commits with the
 *    payment and rolls back with it; `dedupe_key = meta:purchase:{order id}` makes "one Purchase per
 *    order" a database guarantee, whichever callback path (scoped or legacy alias) got there.
 *  - SENT after the commit (`flush()`), never inside it: an HTTP round trip must not hold row locks,
 *    and Meta being slow or down must never delay or fail a payment callback.
 *  - A send Meta did not accept goes back to `pending` with a backoff and `meta:drain` (every minute)
 *    retries it; after the last rung it is `failed`. A token Meta REJECTS (OAuth error 190) fails the
 *    row at once, with a message that names the token — retrying a bad token only hides it.
 *
 * The payload is built and HASHED at enqueue time (Meta's rules: trimmed, lower-cased, SHA-256), so
 * the outbox never holds a raw e-mail or phone. The test event code is applied at SEND time, so
 * removing it from `.env` takes effect on the next send.
 *
 * ONE pixel (the new one, `services.meta_capi.pixel_id`). The browser's COD `Purchase` carries no
 * event id and card orders have no browser event, so nothing needs deduplicating today; the event
 * id is still stable (`purchase-{order number}`) so a browser event added later can match it.
 */
final class MetaConversions
{
    public const CHANNEL = 'meta';

    public const EVENT_PURCHASE = 'order.purchase';

    public static function enabled(): bool
    {
        return trim(config()->string('services.meta_capi.token')) !== '';
    }

    /**
     * The shopper's browser at `add_order` — the last request it makes before the card hand-off.
     * Meta requires the user agent on a website event and matches on the IP and its own `_fbp`/`_fbc`
     * ids; the callback that sends the event has none of them. Written inside the order transaction.
     */
    public static function recordSignals(int $orderId, Request $request): void
    {
        $fb = static function (mixed $v): ?string {
            $v = is_string($v) ? trim($v) : '';

            // `fb.1.1554763741205.1098115397` / `fb.1.1554763741205.AbCdEf…` — anything else is not Meta's.
            return preg_match('/^fb\.\d\.\d{10,13}\.[A-Za-z0-9_\-]{1,200}$/', $v) === 1 ? $v : null;
        };
        $ua = trim((string) $request->userAgent());

        DB::table('core_order_signals')->insertOrIgnore([
            'order_id' => $orderId,
            'ip' => $request->ip(),
            'user_agent' => $ua === '' ? null : mb_substr($ua, 0, 512),
            'fbp' => $fb($request->input('fbp') ?? $request->cookie('_fbp')),
            'fbc' => $fb($request->input('fbc') ?? $request->cookie('_fbc')),
            'created_at' => now(),
        ]);
    }

    /**
     * Enqueue the `Purchase` for a card order whose payment was just confirmed. Call it INSIDE the
     * payment transaction; hand the result to `flush()` after the commit.
     *
     * @return list<int> the outbox row to send (empty when switched off or already queued)
     */
    public function purchase(int $orderId): array
    {
        if (! self::enabled()) {
            return [];
        }
        $event = $this->purchaseEvent($orderId);
        if ($event === null) {
            return [];
        }
        $inserted = DB::table('integration_outbox')->insertOrIgnore([
            'channel' => self::CHANNEL,
            'event' => self::EVENT_PURCHASE,
            'dedupe_key' => 'meta:purchase:'.$orderId,
            'aggregate_type' => 'order',
            'aggregate_id' => $orderId,
            'payload' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return [];
        }
        $id = DB::table('integration_outbox')->where('dedupe_key', 'meta:purchase:'.$orderId)->value('id');

        return is_numeric($id) ? [(int) $id] : [];
    }

    /**
     * Send these rows now. Outside any transaction only — inside one they are left to `meta:drain`.
     *
     * @param  list<int>  $ids
     */
    public function flush(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        // `send_inside_transaction` exists solely because the test suite wraps every feature test in
        // a transaction — the same seam as `notifications.send.inside_transaction` for the mail.
        if (DB::transactionLevel() > 0 && ! config()->boolean('services.meta_capi.send_inside_transaction')) {
            Log::warning('MetaConversions::flush called inside a transaction; leaving '.count($ids).' row(s) to meta:drain.');

            return;
        }
        foreach ($ids as $id) {
            $this->deliver($id);
        }
    }

    /**
     * Claim one pending, due row and send it. Never throws.
     *
     * @return bool true when Meta accepted the event
     */
    public function deliver(int $id): bool
    {
        $claimed = DB::table('integration_outbox')
            ->where('id', $id)->where('channel', self::CHANNEL)->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'available_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        $row = DB::table('integration_outbox')->where('id', $id)->first(['payload', 'attempts']);
        $event = $row === null ? [] : Coerce::arr(json_decode(Row::str(Row::cast($row), 'payload'), true));
        $attempts = $row === null ? 1 : Row::int(Row::cast($row), 'attempts');

        $result = $this->send($event);
        if ($result['ok']) {
            DB::table('integration_outbox')->where('id', $id)->update(['status' => 'sent', 'processed_at' => now(), 'last_error' => null]);

            return true;
        }

        $ladder = array_values(array_map(fn (mixed $v): int => Coerce::int($v), config()->array('services.meta_capi.backoff_minutes')));
        $final = $result['token_rejected'] || $attempts >= count($ladder) + 1;
        DB::table('integration_outbox')->where('id', $id)->update([
            'status' => $final ? 'failed' : 'pending',
            'available_at' => $final ? now() : now()->addMinutes($ladder[max(0, min($attempts - 1, count($ladder) - 1))] ?? 60),
            'processed_at' => $final ? now() : null,
            'last_error' => mb_substr($result['error'] ?? 'unknown', 0, 2000),
        ]);
        Log::warning('meta conversions: send not accepted', ['outbox_id' => $id, 'attempts' => $attempts, 'final' => $final, 'error' => $result['error']]);

        return false;
    }

    /**
     * Send every due pending row (the cron's job). Rows left `sending` longer than 10 minutes — a
     * process killed mid-send — go back to `pending` first.
     *
     * @return array{sent: int, not_sent: int}
     */
    public function drain(int $limit = 100): array
    {
        DB::table('integration_outbox')
            ->where('channel', self::CHANNEL)->where('status', 'sending')
            ->where('available_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending']);

        $sent = 0;
        $notSent = 0;
        foreach (DB::table('integration_outbox')
            ->where('channel', self::CHANNEL)->where('status', 'pending')->where('available_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $this->deliver(Coerce::int($id)) ? $sent++ : $notSent++;
        }

        return ['sent' => $sent, 'not_sent' => $notSent];
    }

    /**
     * POST one event to Meta and say plainly what happened.
     *
     * @param  array<array-key, mixed>  $event
     * @return array{ok: bool, status: int, token_rejected: bool, error: ?string, body: array<array-key, mixed>}
     */
    public function send(array $event): array
    {
        $cfg = config()->array('services.meta_capi');
        $body = ['data' => [$event], 'access_token' => Coerce::str($cfg['token'] ?? '')];
        $test = trim(Coerce::str($cfg['test_event_code'] ?? ''));
        if ($test !== '') {
            $body['test_event_code'] = $test;
        }
        $url = sprintf('https://graph.facebook.com/%s/%s/events', Coerce::str($cfg['graph_version'] ?? 'v23.0'), Coerce::str($cfg['pixel_id'] ?? ''));

        try {
            $response = Http::asJson()->timeout(Coerce::int($cfg['timeout'] ?? 10))->post($url, $body);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 0, 'token_rejected' => false, 'error' => 'could not reach Meta: '.$e->getMessage(), 'body' => []];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'token_rejected' => false, 'error' => $e::class.': '.$e->getMessage(), 'body' => []];
        }

        $json = Coerce::arr($response->json());
        if ($response->successful() && Coerce::int($json['events_received'] ?? 0) >= 1) {
            return ['ok' => true, 'status' => $response->status(), 'token_rejected' => false, 'error' => null, 'body' => $json];
        }

        return ['ok' => false, 'status' => $response->status()] + self::explain($json, $response->status());
    }

    /**
     * Turn Meta's error body into one sentence a person can act on — and say whether it is the token.
     *
     * @param  array<array-key, mixed>  $json
     * @return array{token_rejected: bool, error: string, body: array<array-key, mixed>}
     */
    public static function explain(array $json, int $status): array
    {
        $err = Coerce::arr($json['error'] ?? null);
        $code = Coerce::int($err['code'] ?? 0);
        $message = Coerce::str($err['message'] ?? '');
        $trace = Coerce::str($err['fbtrace_id'] ?? '');
        $tokenRejected = $code === 190 || (Coerce::str($err['type'] ?? '') === 'OAuthException' && in_array($code, [102, 463, 467], true));

        $what = match (true) {
            $tokenRejected => 'THE TOKEN IS WRONG OR EXPIRED — Meta rejected META_CAPI_TOKEN',
            in_array($code, [10, 200, 294], true) => 'the token is valid but has no permission on this pixel',
            $code === 100 => 'Meta refused a parameter (or the pixel id is wrong / not visible to this token)',
            $code === 2635 || str_contains($message, 'version') => 'the Graph API version is not accepted — set META_GRAPH_API_VERSION',
            $status >= 500 => 'Meta had a server error — retried automatically',
            $err === [] => 'Meta answered without an error but did not report the event as received',
            default => 'Meta did not accept the event',
        };

        return [
            'token_rejected' => $tokenRejected,
            'error' => sprintf('%s (HTTP %d, code %d)%s%s', $what, $status, $code, $message !== '' ? ': '.$message : '', $trace !== '' ? ' [fbtrace '.$trace.']' : ''),
            'body' => $json,
        ];
    }

    /**
     * The event for a paid card order, hashed — or null when the order does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function purchaseEvent(int $orderId): ?array
    {
        $order = DB::table('orders as o')
            ->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')
            ->where('o.id', $orderId)
            ->first(['o.order_number', 'o.total_price_for_order', 'o.user_id', 's.domain', 's.currency']);
        if ($order === null) {
            return null;
        }
        $order = Row::cast($order);
        $customer = OrderCustomer::of($orderId);
        $signals = DB::table('core_order_signals')->where('order_id', $orderId)->first(['ip', 'user_agent', 'fbp', 'fbc']);
        $signals = $signals === null ? null : Row::cast($signals);

        $contents = [];
        foreach (DB::table('order_items')->where('order_id', $orderId)->whereNotNull('product_id')->orderBy('id')
            ->get(['product_id', 'quantity', 'piece_price']) as $line) {
            $line = Row::cast($line);
            $contents[] = ['id' => (string) Row::int($line, 'product_id'), 'quantity' => Row::int($line, 'quantity'), 'item_price' => (float) Row::str($line, 'piece_price')];
        }

        $user = array_filter([
            'em' => self::hashed(self::normEmail($customer?->email)),
            'ph' => self::hashed(self::normPhone($customer?->phone)),
            'fn' => self::hashed(self::normName($customer?->name, first: true)),
            'ln' => self::hashed(self::normName($customer?->name, first: false)),
            'country' => self::hashed('eg'),
            'external_id' => self::hashed($customer?->userId !== null ? 'user-'.$customer->userId : null),
            'client_ip_address' => $signals === null ? null : Row::nstr($signals, 'ip'),
            'client_user_agent' => $signals === null ? null : Row::nstr($signals, 'user_agent'),
            'fbp' => $signals === null ? null : Row::nstr($signals, 'fbp'),
            'fbc' => $signals === null ? null : Row::nstr($signals, 'fbc'),
        ], fn (mixed $v): bool => $v !== null && $v !== []);

        $domain = Row::nstr($order, 'domain') ?? 'watchizereg.com';
        $number = Row::str($order, 'order_number');

        return [
            'event_name' => 'Purchase',
            'event_time' => now()->getTimestamp(),
            'event_id' => 'purchase-'.$number,
            // Meta requires the browser's user agent for a `website` event; an order placed before its
            // signals were recorded is sent as `other` rather than refused.
            'action_source' => isset($user['client_user_agent']) ? 'website' : 'other',
            'event_source_url' => 'https://'.$domain.'/',
            'user_data' => $user,
            'custom_data' => [
                'currency' => Row::nstr($order, 'currency') ?? 'EGP',
                'value' => round((float) Row::str($order, 'total_price_for_order'), 2),
                'order_id' => $number,
                'content_type' => 'product',
                'content_ids' => array_map(fn (array $c): string => $c['id'], $contents),
                'contents' => $contents,
                'num_items' => array_sum(array_map(fn (array $c): int => $c['quantity'], $contents)),
            ],
        ];
    }

    /** @return list<string>|null one SHA-256 of an already-normalised value, as Meta's arrays expect */
    private static function hashed(?string $value): ?array
    {
        return $value === null || $value === '' ? null : [hash('sha256', $value)];
    }

    private static function normEmail(?string $email): ?string
    {
        $e = mb_strtolower(trim((string) $email));

        return filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : null;
    }

    /** Digits only, with Egypt's country code: 01012345678 → 201012345678. */
    public static function normPhone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = '20'.substr($d, 1);
        } elseif (strlen($d) === 10 && str_starts_with($d, '1')) {
            $d = '20'.$d;
        }

        return strlen($d) >= 10 ? $d : null;
    }

    private static function normName(?string $name, bool $first): ?string
    {
        $parts = preg_split('/\s+/u', mb_strtolower(trim((string) $name))) ?: [];
        $parts = array_values(array_filter($parts, fn (string $p): bool => $p !== ''));
        if ($parts === [] || (! $first && count($parts) < 2)) {
            return null;
        }
        $pick = $first ? $parts[0] : $parts[count($parts) - 1];
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $pick) ?? '';

        return $clean === '' ? null : $clean;
    }
}
