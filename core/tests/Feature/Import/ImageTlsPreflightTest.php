<?php

use App\Domain\Import\CoverImages;

/*
 * The import refuses to start when this machine cannot verify TLS — because that failure is SILENT.
 *
 * ── The failure it catches ───────────────────────────────────────────────────────────────────
 *
 * A stock PHP on Windows ships with `curl.cainfo` unset, so every HTTPS fetch dies with *"SSL
 * certificate problem: unable to get local issuer certificate"*. `MEDIA_CA_BUNDLE` exists for
 * exactly this — added after the first real import stored 79 products and zero images — and the
 * overnight run of 2026-09-17 hit it a SECOND time, because `.env.example` documented the variable
 * and `.env` did not carry it.
 *
 * Nothing about that run looked wrong. Products imported, counters incremented, the command exited
 * zero. Every product was simply marked `image` and had none. It was noticed 400 rows in only
 * because image coverage read 26% instead of 93% — and even that 26% was an artefact, the share of
 * urls an earlier run had already cached to disk.
 *
 * ── Why the guard is narrow ─────────────────────────────────────────────────────────────────
 *
 * A dead link, a timeout or a refused connection says nothing about the machine: supplier files are
 * full of dead links, and aborting a 7 000-row import over one of them would be a worse failure than
 * the one being prevented. A certificate error is a property of the HOST — identical for every url
 * on the internet, and fixed by one line in `.env`. Only that stops a run, and both halves are
 * asserted below.
 *
 * ── These cases reach the network, and say so ───────────────────────────────────────────────
 *
 * A certificate is a fact about a live TLS handshake; there is no way to assert it without one. So
 * the network cases SKIP when the supplier host is unreachable rather than failing a build on a
 * train — and the skip message names the reason, because a silently skipped test is the same class
 * of problem as the bug this file is about.
 */

/** The supplier host these cases probe. Real, because a certificate has to come from somewhere. */
const TLS_PROBE_URL = 'https://www.brandfashionegy.com/wp-content/uploads/2022/11/1791594-01.jpg';

/** Can we open a TCP connection to the probe host at all? (No TLS — that is what is under test.) */
function tlsProbeHostReachable(): bool
{
    $socket = @fsockopen('ssl://www.brandfashionegy.com', 443, $errno, $error, 5)
        ?: @fsockopen('tcp://www.brandfashionegy.com', 443, $errno, $error, 5);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}

it('reports a certificate problem when no CA bundle can satisfy the host', function () {
    /*
     * Pointing the bundle at a file containing no certificates reproduces the condition
     * deterministically: cURL has something to verify AGAINST and it satisfies nothing, which is the
     * same refusal an unset `curl.cainfo` produces, without depending on a host that is deliberately
     * mis-configured.
     */
    $empty = tempnam(sys_get_temp_dir(), 'ca');
    file_put_contents($empty, "# no certificates in here at all\n");
    config(['media.ca_bundle' => $empty]);

    $problem = CoverImages::tlsProblem(TLS_PROBE_URL);

    @unlink($empty);

    expect($problem)->toBeString()
        // The sentence has to carry the FIX, not just the symptom.
        ->and($problem)->toContain('MEDIA_CA_BUNDLE')
        ->and($problem)->toContain('--images=none')
        // …and it must say out loud that the workaround nobody should reach for is not on offer.
        ->and($problem)->toContain('never disabled');
})->skip(fn () => ! tlsProbeHostReachable(), 'the supplier host is unreachable — a certificate needs a handshake');

it('says nothing about a WORKING machine, so it cannot block an ordinary run', function () {
    /*
     * The direction that matters most: a guard that fired on a healthy machine would stop every
     * import on every host, which is far worse than the bug it prevents.
     *
     * It reads the REAL `media.ca_bundle` rather than a hard-coded path, so on a correctly set up
     * workstation this case is also a live check that `.env` still carries a usable bundle — the one
     * assertion that would have caught 2026-09-17 before the run rather than 400 products into it.
     */
    $bundle = config('media.ca_bundle');
    expect(CoverImages::tlsProblem(TLS_PROBE_URL))->toBeNull(
        is_string($bundle) && $bundle !== ''
            ? "MEDIA_CA_BUNDLE is [{$bundle}] and it did not verify the supplier's certificate"
            : 'MEDIA_CA_BUNDLE is not set and this machine cannot verify certificates without it',
    );
})->skip(fn () => ! tlsProbeHostReachable(), 'the supplier host is unreachable — a certificate needs a handshake');

it('says nothing about a DEAD LINK — a 404 is the supplier\'s problem, not the machine\'s', function () {
    /*
     * THE case that keeps the guard narrow. Their export carries dead urls; if a missing file read
     * as "TLS is broken", one bad row would abort a run of thousands. The check must be blind to the
     * response code.
     */
    $dead = 'https://www.brandfashionegy.com/wp-content/uploads/no-such-file-'.bin2hex(random_bytes(6)).'.jpg';

    expect(CoverImages::tlsProblem($dead))->toBeNull();
})->skip(fn () => ! tlsProbeHostReachable(), 'the supplier host is unreachable — a certificate needs a handshake');

it('says nothing about a PLAIN HTTP url — there is no certificate to verify', function () {
    // No network call on this path at all, so this one case runs everywhere and keeps the file from
    // being entirely skippable.
    expect(CoverImages::tlsProblem('http://example.test/some-image.jpg'))->toBeNull();
});
