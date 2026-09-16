<?php

use Tests\Support\T;

/*
 * No credential may enter committable code (review 🔴-1, 2026-09-15).
 *
 * ── Why a test and not a review habit ───────────────────────────────────────────────────────
 *
 * This repository is PUBLIC. A key committed once is exposed permanently — `git rm` does not
 * un-publish it, which is why the decision on this finding was "move AND rotate" rather than
 * "move". The only defence that keeps working is one that runs on every battery.
 *
 * ── What it scans, and why that is the right set ────────────────────────────────────────────
 *
 * GIT-TRACKED files only. "Committable" is the property that matters: a secret in an untracked
 * scratch file is a local hygiene problem, and a secret in a tracked file is a disclosure. Asking
 * git rather than the filesystem is also what makes the test honest about `.gitignore`.
 *
 * ── The inventory, and why it is not an allowlist ───────────────────────────────────────────
 *
 * {@see KNOWN} is not permission — it is the list of exposures that EXIST TODAY and are waiting on
 * a rotation only the developer can perform (they hold the production host and the storefront
 * deploy). Two properties make it a ratchet rather than a carpet:
 *
 *   • anything NOT on the list fails immediately — a new secret cannot be added quietly;
 *   • anything ON the list that has been cleaned up ALSO fails, with "remove it from KNOWN" —
 *     so the list cannot rot into a lie, the way an unchecked exemption list always does.
 *
 * Fingerprints, never values: this file is itself committable, and a test that pinned the secret
 * it is guarding would be the bug it exists to prevent.
 */

/**
 * The exposures that exist today, by fingerprint, each with what must happen to clear it.
 *
 * @var array<string, array{file: string, what: string, action: string}>
 */
const KNOWN = [
    '3d6ad3734718' => [
        'file' => 'Frontend-next/.env.production',
        'what' => 'the LIVE public API key (NEXT_PUBLIC_PUBLIC_API_KEY), tracked and committed 3+ times',
        'action' => 'rotate on the production host, then untrack the file and .gitignore it',
    ],
    /*
     * `1e463e2c7198` — a SECOND 64-character key, quoted into `audit/watchizer-audit.md`, was
     * REDACTED from the working tree on 2026-09-15 and is therefore no longer on this list: the
     * staleness check below would fail if it were.
     *
     * Redaction is not remediation. The value remains in git history and this repository is
     * public, so it must be treated as compromised and rotated — removing it from the tree only
     * stops it being copied forward.
     */
    '5f5151b7a199' => [
        'file' => 'Frontend-next/app/layout.jsx + Frontend/index.html',
        'what' => 'a Google site-verification token, hard-coded in two places',
        'action' => 'NOT a credential — it is designed to be public in a meta tag. Move to env for hygiene only; no rotation needed',
    ],
];

/**
 * Every git-tracked text file, as path => contents.
 *
 * @return array<string, string>
 */
function trackedFiles(): array
{
    $root = dirname(base_path());          // the repo root, one level above core/
    $listing = shell_exec('cd '.escapeshellarg($root).' && git ls-files');
    expect($listing)->toBeString('git ls-files produced nothing — is this a checkout?');

    /*
     * Lockfiles are generated and are thousands of `sha512-…==` integrity values; the pre-commit
     * hook is the OTHER secret scanner and quoting a pattern is not carrying a secret. Both drowned
     * the signal on the first run of this test, which is the only reason they are named here.
     */
    $skip = '/(node_modules|[\/\\\\]vendor[\/\\\\]|-lock\.json$|composer\.lock$|git-hooks|\.(lock|map|svg|png|jpe?g|webp|ico|gif|pdf|woff2?|ttf|hdr|zip|sql)$)/i';

    $files = [];
    foreach (explode("\n", T::str($listing)) as $relative) {
        $relative = trim($relative);
        if ($relative === '' || preg_match($skip, $relative) === 1) {
            continue;
        }
        $absolute = $root.'/'.$relative;
        if (! is_file($absolute) || filesize($absolute) > 2_000_000) {
            continue;
        }
        $contents = @file_get_contents($absolute);
        if (is_string($contents) && $contents !== '' && ! str_contains(substr($contents, 0, 8000), "\0")) {
            $files[$relative] = $contents;
        }
    }

    return $files;
}

/** The fingerprint this file reports a secret by. Never the secret. */
function fingerprint(string $secret): string
{
    return substr(hash('sha256', $secret), 0, 12);
}

/**
 * Candidate secrets in one file: long, high-entropy, and not obviously something else.
 *
 * @return list<string>
 */
function secretsIn(string $path, string $contents): array
{
    $out = [];
    // Values whose VARIABLE NAME already declares them a credential: exempt from the entropy test.
    $named = [];

    /*
     * 1. An env assignment whose NAME says credential.
     *
     * These bypass the ENTROPY shape test below, and that is the point. The shape test requires
     * mixed case, which is right for a pasted bare literal and WRONG here: an HMAC secret is
     * conventionally lower-case hex, so `PAYMOB_HMAC_SECRET=9c1e5a7b…` failed the mixed-case rule
     * and was discarded — a second silent miss, found the same day as the JWT one (2026-09-15).
     *
     * When the variable's own NAME says SECRET, no entropy heuristic is needed or wanted: the
     * author already told us what it is. Only placeholders are filtered out.
     */
    if (preg_match_all('/^[ \t]*(?:export[ \t]+)?([A-Z0-9_]*(?:KEY|SECRET|TOKEN|PASSWORD|DSN)[A-Z0-9_]*)[ \t]*=[ \t]*["\']?([^\s"\'#]+)/m', $contents, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $value = $match[2];
            if (strlen($value) >= 16 && ! isAPlaceholder($value)) {
                $named[] = $value;
            }
        }
    }

    // 2. A long high-entropy literal in source — the shape a pasted key has.
    if (preg_match_all('/["\'`]([A-Za-z0-9_-]{32,})["\'`]/', $contents, $matches) > 0) {
        foreach ($matches[1] as $literal) {
            $out[] = $literal;
        }
    }

    /*
     * 3. A JWT — and this is the shape the guard USED TO MISS (found 2026-09-15).
     *
     * `looksLikeASecret()` requires alphanumerics, `-` and `_` only, which is what stopped the
     * first run drowning in package-lock integrity hashes. A JWT carries DOTS, so every one of them
     * failed that rule and was discarded silently — and a Paymob API key IS a JWT
     * (`eyJhbGciOi….eyJjbGFzcyI6….signature`). The guard would have answered "no credential-shaped
     * literal in committable code" over a committed Paymob key, which is the worst kind of wrong:
     * a negative that reads as a clean bill of health.
     *
     * Matched structurally rather than by length, so truncation or wrapping cannot hide one.
     */
    if (preg_match_all('/(eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]+)?)/', $contents, $matches) > 0) {
        foreach ($matches[1] as $jwt) {
            $out[] = $jwt;
        }
    }

    $shaped = array_filter($out, static fn (string $value): bool => looksLikeASecret($value));

    return array_values(array_unique(array_merge($named, $shaped)));
}

/**
 * A placeholder rather than a real value.
 *
 * Pulled out of `looksLikeASecret()` so the name-declared path can use it WITHOUT the entropy
 * rules — the two questions are different: "is this shaped like a key" and "did somebody paste a
 * real one where the example value belongs".
 */
function isAPlaceholder(string $value): bool
{
    return preg_match('/^(your|example|changeme|placeholder|test|dummy|sample|xxx|null|true|false|base64:)/i', $value) === 1
        || preg_match('/^[.\-_]*$/', $value) === 1
        // `VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"` — an interpolation is a reference to a value,
        // not a value. `.env.example` is full of them and every one is by definition not a secret.
        || preg_match('/^\$?\{?\$?[A-Z0-9_]+\}?$/', $value) === 1;
}

/**
 * Is this actually a secret, or just a long string?
 *
 * The filter is deliberately about SHAPE rather than a vocabulary of known-good words: a key is
 * long, mixes cases and digits, and carries no word boundaries. A package name, a class path, a
 * base64 image and a hash all fail at least one of those.
 */
function looksLikeASecret(string $value): bool
{
    if (strlen($value) < 32) {
        return false;
    }
    /*
     * A JWT is a secret whatever else it looks like — Paymob's API key is one, and so is any
     * bearer token somebody pastes into a helper script. Checked FIRST, because the alphanumeric
     * rule below would discard it for containing dots. That was the gap until 2026-09-15.
     */
    if (preg_match('/^eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}/', $value) === 1) {
        return true;
    }
    /*
     * ALPHANUMERIC (plus `-` and `_`) only. Every OTHER key in this project is; a base64 blob is
     * not. This one rule is what separates the real credentials from the hundred integrity hashes
     * the first run of this test reported.
     */
    if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return false;
    }
    if (preg_match('/^(sha\d{3}|md5|integrity)/i', $value) === 1) {
        return false;
    }
    // Placeholders are the commonest match and the least interesting.
    if (preg_match('/^(your|example|changeme|placeholder|test|dummy|sample|xxx)/i', $value) === 1) {
        return false;
    }
    // Words, paths, namespaces and MIME types are not keys.
    if (preg_match('/[\/\\\\]|::|\.(js|ts|jsx|tsx|php|css|json|html|vue|svg|png)$/i', $value) === 1) {
        return false;
    }
    if (substr_count($value, '-') > 3 || substr_count($value, '_') > 3) {
        return false;             // kebab/snake identifiers, e.g. a long package name
    }
    // A key mixes cases AND digits. A sha1/sha256 digest is hex-only and is excluded by that.
    $hasLower = preg_match('/[a-z]/', $value) === 1;
    $hasUpper = preg_match('/[A-Z]/', $value) === 1;
    $hasDigit = preg_match('/\d/', $value) === 1;

    return $hasLower && $hasUpper && $hasDigit;
}

it('lets NO new credential into committable code', function () {
    $found = [];

    foreach (trackedFiles() as $path => $contents) {
        // This file declares fingerprints on purpose; scanning it would report its own inventory.
        if (str_contains($path, 'SecretsInRepoTest.php')) {
            continue;
        }
        foreach (secretsIn($path, T::str($contents)) as $secret) {
            $found[fingerprint($secret)] ??= $path;
        }
    }

    $new = array_diff_key($found, KNOWN);

    $report = [];
    foreach ($new as $print => $path) {
        $report[] = "{$path} (fingerprint {$print})";
    }

    expect($report)->toBe([], "a credential-shaped literal reached committable code:\n  ".implode("\n  ", $report)
        ."\nMove it to env. If it is genuinely not a secret, say why in KNOWN rather than widening the filter.");
});

it('CATCHES every credential shape this project has actually leaked', function () {
    /*
     * The guard's own test. Without it, "the scan is clean" means only that the scan found nothing —
     * which is equally true of a scan that cannot see anything. Every shape below is one this
     * project has really committed at least once (AGENTS §3: five or six now), written as a
     * synthetic value so this file never carries a real one.
     *
     * The JWT case is the one that matters most: it FAILED before 2026-09-15, because the
     * alphanumeric filter that keeps package-lock hashes out also discarded every dotted token —
     * and a Paymob API key is a JWT.
     */
    $shapes = [
        'a 64-char API key' => 'PUBLIC_API_KEY=3f8a1c9e2b7d4f6a8c0e1b3d5f7a9c2e4b6d8f0a1c3e5b7d9f2a4c6e8b0d1f3a',
        /*
         * An HMAC secret is conventionally LOWER-CASE HEX, which is exactly what the mixed-case
         * entropy rule used to throw away. It is written here the way one actually appears — as a
         * named assignment — because that is the path that now catches it.
         *
         * A bare, unnamed lower-case hex literal is deliberately NOT flagged: at that point it is
         * indistinguishable from a git SHA or a sha256 digest, both of which this repository's own
         * docs quote legitimately. Flagging those would produce the noise that gets a guard
         * switched off, and a switched-off guard protects nothing.
         */
        'an HMAC secret' => 'PAYMOB_HMAC_SECRET=9c1e5a7b3d0f2a4c6e8b1d3f5a7c9e0b2d4f6a8c1e3b5d7f9a0c2e4b6d8f1a3c',
        'a public storefront key' => 'NEXT_PUBLIC_PUBLIC_API_KEY=7a3c9e1b5d7f0a2c4e6b8d1f3a5c7e9b0d2f4a6c8e1b3d5f7a9c0e2b4d6f8a1c',
        'a Paymob API key (JWT)' => "'".'eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9'
            .'.eyJjbGFzcyI6Ik1lcmNoYW50IiwicHJvZmlsZV9waWQiOjEyMzQ1Nn0'
            .".QZm9vYmFyYmF6cXV4c2VjcmV0c2lnbmF0dXJldmFsdWVoZXJlMTIzNDU2'",
    ];

    $missed = [];
    foreach ($shapes as $label => $sample) {
        if (secretsIn('probe.php', $sample) === []) {
            $missed[] = $label;
        }
    }

    expect($missed)->toBe([]);
});

it('still ignores the things that merely LOOK like keys', function () {
    // The other half: a guard that reports everything gets switched off, and a switched-off guard
    // is the same as none. These are the false positives the first run actually produced.
    $benign = [
        'an integrity hash' => '"sha512-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKlMnOpQrStUv"',
        'a placeholder' => 'APP_SECRET=your-application-secret-value-goes-right-here',
        'a namespaced class' => "'Illuminate\\\\Foundation\\\\Console\\\\AboutCommand\\\\Something'",
    ];

    $falsePositives = [];
    foreach ($benign as $label => $sample) {
        if (secretsIn('probe.php', $sample) !== []) {
            $falsePositives[] = $label;
        }
    }

    expect($falsePositives)->toBe([]);
});

it('fails when a KNOWN exposure has been cleaned up, so the list cannot rot', function () {
    /*
     * The half of this file that makes the inventory trustworthy. An exemption list nobody prunes
     * stops describing reality and starts hiding it — this is the same staleness check
     * `RouteAuthorizationTest` puts on its own open-routes list.
     */
    $found = [];
    foreach (trackedFiles() as $path => $contents) {
        if (str_contains($path, 'SecretsInRepoTest.php')) {
            continue;
        }
        foreach (secretsIn($path, T::str($contents)) as $secret) {
            $found[fingerprint($secret)] = $path;
        }
    }

    $cleared = [];
    foreach (KNOWN as $print => $entry) {
        if (! isset($found[$print])) {
            $cleared[] = $print.' — '.$entry['file'].' — '.$entry['action'];
        }
    }

    expect($cleared)->toBe([], "these exposures are GONE — delete them from KNOWN:\n  ".implode("\n  ", $cleared));
});

it('keeps every real .env out of git, and every .env.example free of real values', function () {
    $root = dirname(base_path());
    $listing = T::str(shell_exec('cd '.escapeshellarg($root).' && git ls-files'));

    $tracked = [];
    foreach (explode("\n", $listing) as $relative) {
        $relative = trim($relative);
        if ($relative !== '' && preg_match('/(^|\/)\.env/', $relative) === 1) {
            $tracked[] = $relative;
        }
    }

    // An `.example` is meant to be tracked. Anything else is a real environment file.
    $real = array_values(array_filter($tracked, static fn (string $p): bool => ! str_contains($p, '.example')));

    /*
     * `Frontend-next/.env.production` is the live one, and it is on the KNOWN list above because
     * removing it needs a rotation first — untracking it while the key is still live would look
     * like a fix and change nothing, since git history keeps the value.
     */
    $unexpected = array_values(array_diff($real, ['Frontend-next/.env.production']));

    expect($unexpected)->toBe([], 'a real .env file is tracked in a PUBLIC repository: '.implode(', ', $unexpected));

    // …and the examples must stay examples.
    foreach ($tracked as $path) {
        if (! str_contains($path, '.example')) {
            continue;
        }
        $contents = T::str(@file_get_contents($root.'/'.$path));
        foreach (secretsIn($path, T::str($contents)) as $secret) {
            expect(isset(KNOWN[fingerprint($secret)]))->toBeTrue("{$path} carries a real-looking value, not a placeholder");
        }
    }
});
