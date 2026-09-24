<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Media\ImagePipeline;
use App\Domain\Media\MediaStore;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fetches ONE image per imported product — the cover — and stores it like any upload (wave 4D).
 *
 * ── Why only the cover ───────────────────────────────────────────────────────────────────────
 *
 * The developer's decision (2026-09-14): *"cover image only (the primary one). The team adds the
 * rest."* The export carries **38 158 image URLs** across 8 070 products — a mode of five each —
 * all on `brandfashionegy.com`, which is the client's own site. Fetching all of them would be
 * tens of gigabytes through somebody's live web server and hours of wall clock, to produce a
 * gallery nobody has reviewed. One image per product makes the catalogue browsable, and 544
 * products have no image at all — reported, because that number is the team's first task.
 *
 * ── What it does NOT do ──────────────────────────────────────────────────────────────────────
 *
 * It does not compute a path, a filename or a URL. {@see MediaStore} owns all three (§5.4), so an
 * imported cover is byte-for-byte the same kind of file as one uploaded through the dashboard:
 * same legacy folder, same `<unix>_<Y-m-d>_<uniqid>.webp` name, same master preset, same
 * renditions. Nothing downstream can tell which one it is looking at, which is the property that
 * matters on switch night.
 *
 * A fetch that fails is a MARKED product, never a failed import: a dead URL on their site must not
 * cost us the row.
 */
final class CoverImages
{
    /** Bytes. A "product photo" larger than this is a mistake on their side, not a photo. */
    private const MAX_BYTES = 12 * 1024 * 1024;

    private const TIMEOUT_SECONDS = 20;

    /**
     * Why the last fetch failed, verbatim from cURL.
     *
     * Kept because the FIRST real run lost 79 covers in a row to "unable to get local issuer
     * certificate" and the report said only `image_fetch_failed` — a number that looks like a few
     * dead URLs on their site and is actually one broken setting on ours. A failure count without a
     * reason is a fact nobody can act on.
     */
    private string $lastError = '';

    /**
     * The cURL error numbers that mean "this machine cannot verify certificates".
     *
     * NUMBERS, not constant names, and the reason is a bug this list already caught: libcurl renamed
     * 60 from `CURLE_SSL_PEER_CERTIFICATE` to `CURLE_PEER_FAILED_VERIFICATION`, and PHP exposes
     * whichever names its build was compiled against — `CURLE_PEER_FAILED_VERIFICATION` is simply
     * undefined on the PHP 8.3 build here, so naming it raised a fatal error inside the guard meant
     * to prevent a silent failure. The numbers have been stable since libcurl 7.x.
     *
     *   58 `CURLE_SSL_CERTPROBLEM`     — our own client certificate is unusable
     *   60 `CURLE_SSL_CACERT` / `CURLE_PEER_FAILED_VERIFICATION` — the peer could not be verified
     *   77 `CURLE_SSL_CACERT_BADFILE`  — the bundle we NAMED could not be read
     *
     * @var list<int>
     */
    private const CERTIFICATE_ERRNOS = [58, 60, 77];

    public function __construct(
        private readonly MediaStore $media,
        private readonly ImageCache $cache,
    ) {}

    /**
     * Download $url and attach it to $productId as its cover. Returns whether it worked.
     */
    public function attach(int $productId, string $url, string $altEn, ImportReport $report): bool
    {
        /*
         * ── LOOK BEFORE YOU FETCH ───────────────────────────────────────────────────────────
         *
         * The shared tree already holds ~50 000 files from earlier runs, and re-fetching what is
         * already there is where the hours went: every repeat costs a download from the client's
         * live web server AND a re-encode into a master plus ten renditions.
         *
         * `ImageCache` is keyed by a hash of the REMOTE URL and lives on disk rather than in the
         * database, because the rebuild wipes `catalog_product_images` and leaves the files. Read
         * its docblock for what it is wrong about in each direction — the short version is that a
         * miss costs exactly what today costs, and a stale hit is possible and is why
         * `--refresh-images` exists.
         */
        $reused = $this->cache->find($url);
        if ($reused !== null) {
            $this->attachStored($productId, $reused, $altEn);
            $report->count('images_reused');

            return true;
        }

        $temporary = null;

        try {
            $temporary = $this->download($url);
            if ($temporary === null) {
                $report->count('image_fetch_failed');
                // Grouped by REASON, so "their site is missing 40 files" and "our TLS is broken"
                // are two different lines in the report instead of one number.
                $report->note('image_failure', $this->lastError === '' ? 'not an image' : $this->lastError);

                return false;
            }

            $stored = $this->media->storePath($temporary, 'product');

            // Only the five fields a reuse needs. `storePath()` also returns a url, a byte count
            // and a skipped list, none of which mean anything on a later run.
            $this->cache->remember($url, [
                'file' => $stored['file'],
                'folder' => $stored['folder'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'renditions' => $stored['renditions'],
            ]);

            DB::table('catalog_product_images')->insert([
                'product_id' => $productId,
                /*
                 * `<folder>/<file>` relative to the shared `Uploads_Images` tree — never a host,
                 * so the tree can move to object storage as a config change (§5.4). The FOLDER is
                 * part of the stored value and this is the one place that got it wrong: the
                 * transform writes `'Product/'.$file` (Step09CoverImages:66), the gallery form
                 * writes `${folder}/${file}` (ImageGallery.tsx), `OrderEmailData` documents the
                 * column as "already carries its folder", and the study's M1 comment spells it
                 * `"Product/169_x.webp"`. Only the importer stored a bare filename, and
                 * `ImageUrl::src()` is a plain concatenation — so every imported cover rendered as
                 * `…/Uploads_Images/<file>.webp` with no `Product/` segment and 404'd on every
                 * screen that shows a cover. Nothing caught it because `media:prune` compares
                 * BASENAMES (MediaAudit::referencedFiles) and the compat layer re-adds the folder
                 * itself (LegacyJson::imageUrl), so the two checks that touch this column are both
                 * blind to the missing prefix.
                 */
                'path' => $stored['folder'].'/'.$stored['file'],
                'is_cover' => 1,
                'sort' => 0,
                'width' => $stored['width'],
                'height' => $stored['height'],
                'alt_en' => mb_substr($altEn, 0, 255),
                'alt_ar' => null,
                'renditions' => $stored['renditions'] === [] ? null : json_encode($stored['renditions']),
                /*
                 * Only the REAL failures (M1s). `skipped` also holds the deliberate omissions —
                 * "source is only 300px wide" is the pipeline refusing to upscale, which is correct
                 * and true of a large share of any supplier catalogue. Storing those would put half
                 * the shop on the review list and teach the team to ignore the marker.
                 */
                'renditions_failed' => self::failureNote($stored['skipped']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (ImagePipeline::hasFailure($stored['skipped'])) {
                $report->count('image_rendition_failed');
                $report->note('image_rendition_failure', implode('; ', array_filter(
                    $stored['skipped'],
                    fn (string $s): bool => str_starts_with($s, ImagePipeline::FAILED_PREFIX),
                )));
            }

            $report->count('images_fetched');

            return true;
        } catch (Throwable $e) {
            $report->count('image_store_failed');

            return false;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * The failure entries of a `skipped` list, as one note — or null when nothing went wrong.
     *
     * @param  list<string>  $skipped
     */
    private static function failureNote(array $skipped): ?string
    {
        $failures = array_values(array_filter(
            $skipped,
            fn (string $s): bool => str_starts_with($s, ImagePipeline::FAILED_PREFIX),
        ));

        return $failures === [] ? null : mb_substr(implode('; ', $failures), 0, 1000);
    }

    /**
     * Point a product at a file that is ALREADY on disk.
     *
     * The same row a fetch writes, from the same shape — so a reused cover and a freshly fetched
     * one are indistinguishable afterwards, which is the property that makes the cache safe to
     * turn off at any time.
     *
     * @param  array{file: string, folder: string, width: int, height: int, renditions: array<int, array<string, string>>}  $stored
     */
    private function attachStored(int $productId, array $stored, string $altEn): void
    {
        DB::table('catalog_product_images')->insert([
            'product_id' => $productId,
            'path' => $stored['folder'].'/'.$stored['file'],
            'is_cover' => 1,
            'sort' => 0,
            'width' => $stored['width'],
            'height' => $stored['height'],
            'alt_en' => mb_substr($altEn, 0, 255),
            'alt_ar' => null,
            'renditions' => $stored['renditions'] === [] ? null : json_encode($stored['renditions']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The bytes, into a temporary file. Null when the URL does not give us an image.
     *
     * Streamed to disk rather than held in memory: 8 000 products at a few hundred KB each is not
     * something to accumulate in a PHP string, and `ImagePipeline` wants a path anyway.
     */
    private function download(string $url): ?string
    {
        /*
         * A LOCAL path is allowed, and it is not an afterthought: the Joyroom photos came out of a
         * PDF and were never on a web server. Copied rather than used in place, so the pipeline's
         * input is always a file this class owns and the `finally` can delete it without ever
         * touching the extraction folder.
         */
        if (preg_match('#^https?://#i', $url) !== 1) {
            if (! is_file($url) || @getimagesize($url) === false) {
                return null;
            }
            $local = tempnam(sys_get_temp_dir(), 'wzimg');
            if ($local === false || ! @copy($url, $local)) {
                return null;
            }

            return $local;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'wzimg');
        if ($temporary === false) {
            return null;
        }

        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);

            return null;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            @unlink($temporary);

            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Their own site, politely identified. An importer that pretends to be a browser is an
            // importer nobody can find in an access log when it goes wrong.
            CURLOPT_USERAGENT => 'WatchizerCoreImporter/1.0 (+catalogue import)',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, int $total, int $downloaded): int {
                return $downloaded > self::MAX_BYTES ? 1 : 0;      // non-zero aborts the transfer
            },
        ]);

        // The CA bundle, when this host needs one told to it. Verification stays ON either way.
        $bundle = config('media.ca_bundle');
        if (is_string($bundle) && $bundle !== '' && is_file($bundle)) {
            curl_setopt($curl, CURLOPT_CAINFO, $bundle);
        }

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->lastError = $ok === false ? curl_error($curl) : '';
        curl_close($curl);
        fclose($handle);

        if ($ok === false || $status < 200 || $status >= 300 || filesize($temporary) === 0) {
            if ($this->lastError === '') {
                $this->lastError = 'HTTP '.$status;
            }
            @unlink($temporary);

            return null;
        }

        // It must actually BE an image. `getimagesize()` reads the header, so a 404 page saved as
        // a .jpg fails here instead of inside the pipeline.
        if (@getimagesize($temporary) === false) {
            @unlink($temporary);

            return null;
        }

        return $temporary;
    }

    /**
     * Can this host fetch over TLS at all? Answered BEFORE a run, against one real URL.
     *
     * ── Why this exists (2026-09-17, and it is the second time) ──────────────────────────────
     *
     * A stock PHP on Windows ships with `curl.cainfo` unset, so every HTTPS fetch fails with
     * *"SSL certificate problem: unable to get local issuer certificate"*. `MEDIA_CA_BUNDLE` was
     * added for exactly this after the first real import run stored 79 products and zero images —
     * and then the overnight run of 2026-09-17 hit it AGAIN, because `.env.example` documents the
     * variable and `.env` simply did not carry it.
     *
     * **The failure is silent, and that is the whole problem.** Every fetch fails, every product is
     * imported and marked `image`, every counter looks plausible, and the run reports success. It
     * was caught 400 products in only because the image-coverage figure was 26% instead of 93% —
     * and 26% is itself an artefact, the share of URLs an earlier run had already cached. Left
     * alone it would have spent a whole night writing thousands of imageless products.
     *
     * So the command asks this question once, up front, and refuses rather than discovering it in
     * the morning.
     *
     * ── Why it only refuses on a CERTIFICATE error ───────────────────────────────────────────
     *
     * A 404, a timeout or a refused connection on ONE url says nothing about the host — supplier
     * files carry dead links all the time, and aborting a 7 000-row run over one of them would be a
     * worse failure than the one this prevents. A certificate error is different in kind: it is a
     * property of THIS machine's cURL, it is identical for every url on the internet, and it is
     * fixed by one line in `.env`. That is the only case worth stopping for.
     *
     * @return string|null the reason to refuse, or null if fetching works (or if the problem is not
     *                     one this machine can be blamed for)
     */
    public static function tlsProblem(string $probeUrl): ?string
    {
        if (! str_starts_with(mb_strtolower($probeUrl), 'https://')) {
            return null;
        }

        $curl = curl_init($probeUrl);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'WatchizerCoreImporter/1.0 (+catalogue import)',
        ]);

        $bundle = config('media.ca_bundle');
        $named = is_string($bundle) && $bundle !== '';
        if ($named && is_file($bundle)) {
            curl_setopt($curl, CURLOPT_CAINFO, $bundle);
        }

        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($ok !== false || ! in_array($errno, self::CERTIFICATE_ERRNOS, true)) {
            return null;
        }

        // Named but wrong is a different mistake from not named at all, and they are fixed
        // differently — so the sentence says which one this is.
        $advice = match (true) {
            ! $named => 'MEDIA_CA_BUNDLE is not set in .env. Point it at a CA bundle — on XAMPP, '
                .'MEDIA_CA_BUNDLE=C:/xampp/apache/bin/curl-ca-bundle.crt',
            ! is_file((string) $bundle) => 'MEDIA_CA_BUNDLE names a file that does not exist: '.$bundle,
            default => 'MEDIA_CA_BUNDLE is set to '.$bundle.', and it did not satisfy this certificate.',
        };

        return 'This machine cannot verify TLS certificates, so EVERY image fetch would fail and every '
            ."product would be imported without one.\n  cURL said: ".$error."\n  ".$advice
            ."\n  Verification is never disabled to work around this."
            ."\n  Or re-run with --images=none if you meant to import without images.";
    }
}
