<?php

namespace App\Console\Commands;

use App\Domain\Analytics\MetaConversions;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use IntlChar;

/**
 * meta:capi-check — is the Conversions API set up right? (B2, 2026-09-29)
 *
 * Built to tell "THE TOKEN IS WRONG" apart from "THE EVENTS ARE NOT FIRING", because the token was
 * carried through a chat that swapped Latin letters for look-alike Cyrillic ones. Four verdicts,
 * each on its own line, each saying what to do:
 *
 *   1. CONFIG   — token, pixel, API version and test code present (the token is never printed:
 *                 its length and a fingerprint are, so a rotation can be seen to have landed);
 *   2. CHARS    — every character of the token that is not A–Z, a–z or 0–9, with its position and
 *                 Unicode name (a Cyrillic "х" shows up here, however right it looks);
 *   3. TOKEN    — Meta's own answer to "can this token see this pixel?" (a read, sends nothing);
 *   4. EVENTS   — with --send-test: one Purchase to Events Manager → Test events. Refused unless
 *                 META_CAPI_TEST_EVENT_CODE is set, so this can never put a fake sale in the stats.
 * Plus the outbox: what real card Purchases are pending, sent or failed, and the last error.
 *
 *   php artisan meta:capi-check              # config, characters, token, outbox
 *   php artisan meta:capi-check --send-test  # … and one test event
 */
final class MetaCapiCheckCommand extends Command
{
    protected $signature = 'meta:capi-check {--send-test : also send one Purchase with the test event code}';

    protected $description = 'Check the Meta Conversions API token, pixel and outbox (never prints the token)';

    public function handle(MetaConversions $meta): int
    {
        $cfg = config()->array('services.meta_capi');
        $token = Coerce::str($cfg['token'] ?? '');
        $pixel = Coerce::str($cfg['pixel_id'] ?? '');
        $version = Coerce::str($cfg['graph_version'] ?? '');
        $test = trim(Coerce::str($cfg['test_event_code'] ?? ''));
        $bad = false;

        // ── 1. CONFIG ──────────────────────────────────────────────────────────────────────
        if ($token === '') {
            $this->error('CONFIG  FAIL — META_CAPI_TOKEN is empty. Set it in core/.env, then `php artisan config:cache`.');

            return self::FAILURE;
        }
        $this->line(sprintf('CONFIG  token: %d chars, fingerprint %s · pixel %s · API %s · test code %s',
            mb_strlen($token), substr(hash('sha256', $token), 0, 12), $pixel, $version, $test !== '' ? $test.' (events go to Test events only)' : 'NOT SET (events count as real)'));

        // ── 2. CHARS ───────────────────────────────────────────────────────────────────────
        $odd = [];
        $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $i => $ch) {
            if (preg_match('/^[A-Za-z0-9]$/', $ch) !== 1) {
                $name = class_exists(IntlChar::class) ? (string) IntlChar::charName($ch) : '';
                $odd[] = sprintf('position %d: U+%04X %s', $i + 1, mb_ord($ch), $name !== '' ? $name : ($ch === ' ' ? 'SPACE' : 'non-alphanumeric'));
            }
        }
        if ($odd !== []) {
            $bad = true;
            $this->error('CHARS   FAIL — the token contains characters a Meta token never has (only A–Z, a–z, 0–9):');
            foreach ($odd as $o) {
                $this->error('          '.$o);
            }
            $this->error('          Fix those characters (or paste a fresh token), then `php artisan config:cache`.');
        } else {
            $this->info('CHARS   OK — letters and digits only'.(str_starts_with($token, 'EAA') ? '' : ' (note: Meta tokens usually start with "EAA")').'.');
        }

        // ── 3. TOKEN ───────────────────────────────────────────────────────────────────────
        try {
            $r = Http::timeout(Coerce::int($cfg['timeout'] ?? 10))
                ->get(sprintf('https://graph.facebook.com/%s/%s', $version, $pixel), ['fields' => 'id,name', 'access_token' => $token]);
            $json = Coerce::arr($r->json());
            if ($r->successful() && Coerce::str($json['id'] ?? '') === $pixel) {
                $this->info(sprintf('TOKEN   OK — Meta accepts it, and it can see pixel %s ("%s").', $pixel, Coerce::str($json['name'] ?? '?')));
            } else {
                $bad = true;
                $x = MetaConversions::explain($json, $r->status());
                $this->error('TOKEN   FAIL — '.$x['error']);
            }
        } catch (ConnectionException $e) {
            $bad = true;
            $this->error('TOKEN   UNKNOWN — could not reach Meta from this server: '.$e->getMessage());
        }

        // ── 4. EVENTS (optional) ───────────────────────────────────────────────────────────
        if ((bool) $this->option('send-test')) {
            if ($test === '') {
                $this->error('EVENTS  REFUSED — META_CAPI_TEST_EVENT_CODE is not set, so a test Purchase would count as a real sale. Set it, config:cache, retry.');
                $bad = true;
            } else {
                $result = $meta->send([
                    'event_name' => 'Purchase',
                    'event_time' => now()->getTimestamp(),
                    'event_id' => 'capi-check-'.now()->getTimestamp(),
                    'action_source' => 'website',
                    'event_source_url' => 'https://watchizereg.com/',
                    'user_data' => [
                        'external_id' => [hash('sha256', 'meta-capi-check')],
                        'client_user_agent' => 'watchizer meta:capi-check',
                        'client_ip_address' => '127.0.0.1',
                    ],
                    'custom_data' => ['currency' => 'EGP', 'value' => 1, 'content_type' => 'product', 'content_ids' => ['capi-check']],
                ]);
                if ($result['ok']) {
                    $this->info(sprintf('EVENTS  OK — Meta received the test Purchase (events_received=%d). Look in Events Manager → pixel %s → Test events → code %s.',
                        Coerce::int($result['body']['events_received'] ?? 0), $pixel, $test));
                } else {
                    $bad = true;
                    $this->error('EVENTS  FAIL — '.($result['error'] ?? 'unknown'));
                }
            }
        }

        // ── the outbox: are real card Purchases being produced and sent? ─────────────────────
        $counts = DB::table('integration_outbox')->where('channel', MetaConversions::CHANNEL)
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $summary = [];
        foreach ($counts as $status => $n) {
            $summary[] = $status.' '.Coerce::int($n);
        }
        $this->line('OUTBOX  card Purchases: '.($summary === [] ? 'none yet (no card payment has completed since B2 was deployed)' : implode(' · ', $summary)));
        $lastError = DB::table('integration_outbox')->where('channel', MetaConversions::CHANNEL)->whereNotNull('last_error')->orderByDesc('id')->value('last_error');
        if (is_string($lastError)) {
            $this->line('        last error: '.$lastError);
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
