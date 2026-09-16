<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LegacyJwt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Switch-night smoke check for the env values the compat layer CANNOT work without:
 * `COMPAT_API_KEY` (= the legacy app's `PUBLIC_API_KEY`), `JWT_SECRET`, and the three IMAGE BASES.
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
 *  3. the image bases: `STOREFRONT_ASSET_BASE`, `COMPAT_ASSET_BASE` and `MEDIA_URL_BASE` decide
 *     what host every rendered `<img src>` points at — the dashboard, the v2 API, the compat
 *     payloads and the order e-mails. They are the keys a developer edits to see local files
 *     during development, which is exactly what makes them the keys that get copied to
 *     production: the `ORDER_MAIL_INLINE` lesson (AGENTS §3), one file up. This check is
 *     CONFIG-ONLY and costs nothing, so it runs on every invocation.
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
                // Rehearsal #3 (2026-09-12) ended with exactly this line and no way to act on it.
                // The unreachable host is the commonest outcome on a workstation, so the remedy
                // belongs HERE rather than in a document nobody has open.
                $this->line('        The legacy app must be answering on that origin first. Locally:');
                $this->line('          1) start it from the legacy checkout — `php artisan serve --port=8011` in `backend/`');
                $this->line('          2) point core at it — COMPAT_LEGACY_BASE in `core/.env` (now: '.$base.')');
                $this->line('          3) re-run this command. On the real hosts, use the legacy origin itself.');
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

        // ── 3. the image asset bases ─────────────────────────────────────────────────────────
        foreach ($this->assetFailures() as $failure) {
            $failures[] = $failure;
        }

        $this->line('');
        if ($failures !== []) {
            $this->error('NOT READY: '.implode('; ', $failures));
            $this->newLine();
            $this->line('WHAT THIS COMMAND NEEDS, exactly — switch-night prerequisite (f):');
            $this->line('  1. COMPAT_API_KEY in `core/.env`  = the VALUE of PUBLIC_API_KEY in the legacy .env');
            $this->line('     (on the legacy host: grep ^PUBLIC_API_KEY .env in backend/). Copy the value by hand.');
            $this->line('  2. JWT_SECRET in `core/.env`      = the VALUE of JWT_SECRET in the legacy .env, byte for byte.');
            $this->line('  3. `php artisan config:clear` on the core host, or the cached config keeps the old values.');
            $this->line('  4. A REACHABLE legacy origin in COMPAT_LEGACY_BASE — the key half is proved by calling a');
            $this->line('     guarded path and comparing 2xx-with-header against 401-without.');
            $this->line('  5. For the JWT half: `--token="<JWT>"` from a real storefront session — sign in on the');
            $this->line('     storefront and copy the token the app holds (it is minted by the legacy host; core');
            $this->line('     only verifies, so it cannot produce one itself). Any unexpired token for any account works.');
            $this->newLine();
            $this->line('  6. The image bases on the PRODUCTION core host: STOREFRONT_ASSET_BASE = COMPAT_ASSET_BASE =');
            $this->line('     the host that serves the shared Uploads_Images tree (https://dash.watchizereg.com until');
            $this->line('     the tree moves), and MEDIA_URL_BASE ABSENT so it follows. A local override of any of them');
            $this->line('     must never be copied to production — the [assets] check above is what catches that.');
            $this->newLine();
            $this->line('Neither secret is ever written into the repository (AGENTS §3): they live only in the two');
            $this->line('`.env` files, and this command prints lengths, never values.');

            return self::FAILURE;
        }

        $this->info('env parity holds for every check that ran.');

        return self::SUCCESS;
    }

    /**
     * The three image bases, checked against each other and against the environment.
     *
     * Why each rule is a rule:
     *
     *  • `media.url_base` already DEFAULTS to `storefront.asset_base.'/Uploads_Images'`, so the only
     *    way it can disagree is an explicit `MEDIA_URL_BASE`. When it does, the thumbnail shown the
     *    instant an upload finishes (`MediaStore::url()` → the 201 of `POST /manage/media`) and the
     *    thumbnail shown after the form reloads (`ImageUrl::src()`) point at DIFFERENT hosts — one
     *    of them wrong, on a screen where the operator cannot tell which. Study §3.14.6.
     *  • `compat.asset_base` is what the compat payloads emit and `storefront.asset_base` is what
     *    the dashboard, the v2 API and the order e-mails emit, from the SAME `Uploads_Images` tree.
     *    A split is legitimate ONLY on a development machine, where the harness needs compat to keep
     *    reproducing the legacy host's strings while the dashboard points at local files. On
     *    production a split means one of the two surfaces is serving 404s.
     *  • And a base that resolves to loopback, a private address, a `.test`/`.local` name or plain
     *    `http` is a DEVELOPMENT value. On production it is the `ORDER_MAIL_INLINE` failure again:
     *    a key edited once for a local convenience, copied forward with the rest of the file, whose
     *    only symptom is that every product photo in the shop and in every e-mail is broken.
     *
     * @return list<string>
     */
    private function assetFailures(): array
    {
        $storefront = rtrim(config()->string('storefront.asset_base'), '/');
        $compat = rtrim(config()->string('compat.asset_base'), '/');
        $media = rtrim(config()->string('media.url_base'), '/');
        // `environment('production')` rather than `isProduction()`: the former is on the
        // Application CONTRACT that `getLaravel()` is typed as, so this stays clean at level 10.
        $production = $this->getLaravel()->environment('production') === true;

        $this->line('');
        $this->line(sprintf('STOREFRONT_ASSET_BASE: %s', $storefront === '' ? 'EMPTY' : $storefront));
        $this->line(sprintf('COMPAT_ASSET_BASE    : %s', $compat === '' ? 'EMPTY' : $compat));
        $this->line(sprintf('media.url_base       : %s', $media === '' ? 'EMPTY' : $media));

        /** @var list<string> $failures */
        $failures = [];
        // A development split and a development base are not failures HERE — they are correct on a
        // developer's machine. They still have to suppress the "OK" line, or the command would
        // print a reassurance directly under the two lines that say what is deliberately non-standard.
        $noted = false;

        if ($media !== $storefront.'/Uploads_Images') {
            $failures[] = 'MEDIA_URL_BASE does not follow STOREFRONT_ASSET_BASE';
            $this->error(
                '  [assets] SPLIT PREVIEW — media.url_base is not STOREFRONT_ASSET_BASE + "/Uploads_Images". '.
                'The thumbnail shown right after an upload and the one shown after a reload would point at '.
                'different hosts. Leave MEDIA_URL_BASE unset so it follows.'
            );
        }

        if ($compat !== $storefront) {
            if ($production) {
                $failures[] = 'COMPAT_ASSET_BASE and STOREFRONT_ASSET_BASE disagree in production';
                $this->error(
                    '  [assets] SPLIT — the compat payloads and the dashboard/v2/e-mails name different image '.
                    'hosts for the SAME Uploads_Images tree. On production exactly one of them can be right.'
                );
            } else {
                $noted = true;
                $this->warn(
                    '  [assets] split, development — compat keeps the legacy host\'s strings (so `compat:diff` '.
                    'still compares like for like) while the dashboard points elsewhere. Intentional locally; '.
                    'never on production.'
                );
            }
        }

        foreach (['STOREFRONT_ASSET_BASE' => $storefront, 'COMPAT_ASSET_BASE' => $compat, 'media.url_base' => $media] as $name => $value) {
            if (! self::isDevelopmentBase($value)) {
                continue;
            }
            if ($production) {
                $failures[] = "{$name} is a development value on a production host";
                $this->error(sprintf(
                    '  [assets] LOCAL VALUE IN PRODUCTION — %s = %s. Every product photo in the shop, in the '.
                    'dashboard and in every order e-mail would be broken. Remove the override.',
                    $name, $value === '' ? '(empty)' : $value,
                ));
            } else {
                $noted = true;
                $this->line(sprintf('  [assets] %s is a development value — correct here, NEVER copy this file to production.', $name));
            }
        }

        /*
         * ── and the WRITE side, which fails in the opposite direction ────────────────────────
         *
         * `MEDIA_ROOT` defaults to `../backend/public/Uploads_Images`, which is only meaningful on
         * a machine where the two checkouts are siblings — this workstation. On a host where they
         * are not, `MediaStore::directory()` does not fail: it MKDIRS the path and starts writing
         * a private tree no web server serves and no `media:prune` scan expects. Every upload
         * appears to work and every image 404s, which is the failure this whole report exists
         * about, one layer lower. So the production host must name the shared mount ABSOLUTELY.
         */
        $root = config()->string('media.root');
        $absolute = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:/', $root) === 1;
        $resolved = $absolute ? $root : base_path($root);
        $this->line(sprintf('MEDIA_ROOT           : %s%s', $root, $absolute ? '' : '  → '.$resolved));

        if ($production && ! $absolute) {
            $failures[] = 'MEDIA_ROOT is a relative path on a production host';
            $this->error(
                '  [assets] RELATIVE MEDIA_ROOT — the default assumes `backend/` sits next to `core/`. '.
                'Off this workstation MediaStore CREATES that directory and writes into a tree nothing '.
                'serves: uploads appear to succeed and every image 404s. Set MEDIA_ROOT to the absolute '.
                'path of the shared Uploads_Images mount.'
            );
        } elseif (! is_dir($resolved)) {
            $noted = true;
            $this->warn(sprintf('  [assets] the media root does not exist yet: %s — the first upload would create it.', $resolved));
        }

        if ($failures === [] && ! $noted) {
            $this->info('  [assets] OK — the three image bases agree'.($production ? ', none is a development value, and the media root is absolute.' : '.'));
        }

        return $failures;
    }

    /**
     * Is this base a value that can only work on a developer's machine?
     *
     * Deliberately generous: an empty base, a loopback or private address, a development TLD, or
     * plain `http` anywhere. Each one is either unreachable from the internet or a downgrade the
     * browser will block on an https page, so none of them can be a production asset host, and a
     * false positive costs one line of output while a false negative costs the shop's photographs.
     */
    private static function isDevelopmentBase(string $base): bool
    {
        if ($base === '') {
            return true;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME);
        if (! is_string($scheme) || strtolower($scheme) !== 'https') {
            return true;
        }

        $host = parse_url($base, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return true;
        }
        $host = strtolower($host);

        foreach (['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'] as $loopback) {
            if ($host === $loopback) {
                return true;
            }
        }

        foreach (['.test', '.local', '.localhost', '.invalid', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // RFC 1918 and link-local, written out rather than computed: the set is fixed and short.
        return preg_match('/^(10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/', $host) === 1;
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
