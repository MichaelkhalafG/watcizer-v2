<?php

namespace App\Http\Controllers\Compat;

use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\ReEngagement;
use App\Http\Controllers\Controller;
use App\Transform\Row;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The re-engagement e-mail's unsubscribe (2026-10-01): /unsubscribe/{send}/{signature} on the API
 * host. The signature is an HMAC of the send's id (`BulkMailer::unsubscribeSignature`), so the link
 * names no address and cannot be forged for someone else's.
 *
 *  GET  → a page with one button (link scanners open links; a GET never unsubscribes)
 *  POST → unsubscribed from this storefront's marketing mail, for good. Also what a mail app's own
 *         "Unsubscribe" button sends (List-Unsubscribe-Post: One-Click, RFC 8058).
 */
final class UnsubscribeController extends Controller
{
    public function page(int $send, string $signature): Response
    {
        abort_unless(self::valid($send, $signature), 404);

        return response()->view('reengagement.unsubscribe', ['send' => $send, 'signature' => $signature, 'done' => false]);
    }

    public function unsubscribe(int $send, string $signature): Response
    {
        abort_unless(self::valid($send, $signature), 404);
        $raw = DB::table('core_reengagement_sends as s')->join('core_reengagement_runs as r', 'r.id', '=', 's.run_id')
            ->where('s.id', $send)->first(['s.email', 'r.storefront_id']);
        abort_unless(is_object($raw), 404);
        $row = Row::cast($raw);
        ReEngagement::unsubscribe(Row::int($row, 'storefront_id'), Row::str($row, 'email'));

        return response()->view('reengagement.unsubscribe', ['send' => $send, 'signature' => $signature, 'done' => true]);
    }

    private static function valid(int $send, string $signature): bool
    {
        return hash_equals(BulkMailer::unsubscribeSignature($send), $signature);
    }
}
