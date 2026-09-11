<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LegacyJwt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Switch-night smoke check for the two env values the compat layer CANNOT work without:
 * `COMPAT_API_KEY` (= the legacy app's `PUBLIC_API_KEY`) and `JWT_SECRET`.
 *
 *   php artisan compat:env-parity
 *   php artisan compat:env-parity --token="<a JWT from a live storefront session>"
 *
 * Why this exists as a COMMAND rather than a paragraph in the runbook (study §3.4.1 (f)): the two
 * values live in two `.env` files on two hosts, and on this workstation they do not match — core's
 * key is 13 characters, the legacy app's is 64. The legacy `CheckApiMiddleware` compares the
 * `Api-Code` header against `config('services.public_api_key')` before anything else runs, so a
 * mismatch is not a degraded mode: **every proxied storefront request is rejected wholesale, with
 * a 401 that looks like an auth bug**. The same is true of the JWT: core VERIFIES tokens the legacy
 * host MINTS (`App\Support\LegacyJwt`), so a different secret logs every signed-in customer out at
 * the moment of the flip.
 *
 * It never prints, compares or derives a secret. It proves parity the only way that is meaningful
 * across two hosts — by exercising the behaviour:
 *
 *  1. the key: call a legacy path that the middleware guards, once WITH the header core is
 *     configured to send and once WITHOUT. A pass is 2xx with and 401 without. The negative half
 *     matters as much as the positive one: a 200 for both means the middleware is not active on
 *     that path and the check proved nothing.
 *  2. the secret: verify a real token with core's own verifier. The token comes from the developer
 *     (`--token`, read from a live storefront session), because core cannot mint one and must not
 *     be able to.
 *
 * Read-only over HTTP, writes nothing, touches no database.
 */
final class CompatEnvParityCommand extends Command
{
    protected $signature = 'compat:env-parity
        {--base= : legacy origin to test against (defaults to compat.legacy_base)}
        {--path=/api/catalog/meta : a legacy path guarded by CheckApiMiddleware}
        {--token= : a JWT from a live storefront session, to verify the shared secret}';

    protected $description = 'Prove core carries the legacy PUBLIC_API_KEY and JWT_SECRET (switch-night prerequisite (f))';

    public function handle(): int
    {
        $base = rtrim($this->stringOption('base') ?: config()->string('compat.legacy_base'), '/');
        $path = '/'.ltrim($this->stringOption('path') ?: '/api/catalog/meta', '/');
        $key = config()->string('compat.api_key');
        $secret = config('compat.jwt_secret');

        $this->line('');
        $this->line("legacy origin : {$base}");
        $this->line("guarded path  : {$path}");
        $this->line(sprintf('COMPAT_API_KEY: %s', $key === '' ? 'NOT SET' : sprintf('set, %d characters', strlen($key))));
        $this->line(sprintf('JWT_SECRET    : %s', is_string($secret) && $secret !== '' ? sprintf('set, %d characters', strlen($secret)) : 'NOT SET'));
        $this->line('');

        $failures = [];

        // ── 1. the Api-Code key ──────────────────────────────────────────────────────────────
        if ($key === '') {
            $failures[] = 'COMPAT_API_KEY is empty in this environment';
            $this->error('  [key] COMPAT_API_KEY is not set — every storefront request would be rejected.');
        } else {
            $with = $this->status($base.$path, ['Api-Code' => $key]);
            $without = $this->status($base.$path, []);

            if ($with === null) {
                $failures[] = "could not reach {$base}{$path}";
                $this->error("  [key] could not reach {$base}{$path} — nothing was proved.");
            } elseif ($with >= 200 && $with < 300 && $without === 401) {
                $this->info(sprintf('  [key] OK — %d with the header, %d without. The keys match.', $with, $without));
            } elseif ($with === 401) {
                $failures[] = 'the legacy host rejects core\'s Api-Code (401)';
                $this->error(
                    '  [key] MISMATCH — the legacy host answered 401 to core\'s Api-Code. '.
                    'Set core\'s COMPAT_API_KEY to the legacy app\'s PUBLIC_API_KEY.'
                );
            } elseif ($without !== null && $without >= 200 && $without < 300) {
                $failures[] = 'the path is not guarded, so nothing was proved';
                $this->error(sprintf(
                    '  [key] INCONCLUSIVE — %s answered %d WITHOUT the header too, so it is not guarded '.
                    'by CheckApiMiddleware. Re-run with --path pointing at a guarded path.',
                    $path, $without,
                ));
            } else {
                $failures[] = sprintf('unexpected statuses: %d with, %s without', $with, $without === null ? 'unreachable' : (string) $without);
                $this->error(sprintf('  [key] UNEXPECTED — %d with the header, %s without.', $with, $without === null ? 'unreachable' : (string) $without));
            }
        }

        // ── 2. the shared JWT secret ─────────────────────────────────────────────────────────
        $token = $this->stringOption('token');
        if (! is_string($secret) || $secret === '') {
            $failures[] = 'JWT_SECRET is empty in this environment';
            $this->error('  [jwt] JWT_SECRET is not set — every signed-in storefront request would read as a guest.');
        } elseif ($token === '') {
            // NOT a failure: the check is simply not performed, and saying so is the honest
            // outcome. Reporting a pass here would be the worst thing this command could do.
            $this->warn(
                '  [jwt] NOT CHECKED — pass --token="<JWT from a live storefront session>" to verify the '.
                'shared secret. Core cannot mint one (issuance stays on the legacy host), so the token '.
                'has to come from a real session.'
            );
        } else {
            $subject = LegacyJwt::subject($token);
            if ($subject === null) {
                $failures[] = 'core cannot verify a legacy-minted JWT';
                $this->error(
                    '  [jwt] MISMATCH (or the token is expired/malformed) — core\'s verifier rejected it. '.
                    'If the token is fresh, core\'s JWT_SECRET is not the legacy app\'s JWT_SECRET, and '.
                    'every signed-in customer is logged out at the flip.'
                );
            } else {
                $this->info(sprintf('  [jwt] OK — core verified the token and read subject %d. The secrets match.', $subject));
            }
        }

        $this->line('');
        if ($failures !== []) {
            $this->error('NOT READY: '.implode('; ', $failures));
            $this->line('Fix: core `.env` COMPAT_API_KEY = legacy `.env` PUBLIC_API_KEY, and the two JWT_SECRETs identical. Then `php artisan config:clear`.');

            return self::FAILURE;
        }

        $this->info('env parity holds for every check that ran.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $headers
     * @return int|null the status, or null when the host could not be reached at all
     */
    private function status(string $url, array $headers): ?int
    {
        try {
            return Http::withHeaders($headers)->timeout(15)->acceptJson()->get($url)->status();
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
