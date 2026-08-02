<?php

namespace App\Console\Commands;

use App\Support\LogoAnalyzer;
use Illuminate\Console\Command;

/**
 * Issue 3C — repair brand + sub-type logos to REAL transparency, in place.
 *
 * Uses Imagick directly (required — the server has it). Analysis is shared with
 * images:audit-logos via LogoAnalyzer, so verdicts match the audit.
 *
 * Per-file policy (deliberately conservative — "rather skip 10 than mangle 1"):
 *
 *  OK (already has alpha) .............. untouched.
 *
 *  CHECKERBOARD_DETECTED
 *    • also real-alpha ................. SKIP → manual  (genuine alpha mixed with a
 *                                        baked checker — flood-fill would risk the
 *                                        real transparency; too dangerous to automate)
 *    • JPEG ............................ SKIP → manual  (JPEG can't store alpha)
 *    • PNG/WebP/GIF .................... edge flood-fill keying of the two grid greys;
 *                                        keep only edge-reachable background (never
 *                                        punch interior holes). Confidence gate on the
 *                                        resulting transparent area → low ⇒ SKIP manual.
 *
 *  NO_ALPHA
 *    • uniform near-white border + low colour complexity + PNG/WebP
 *                       ............... edge flood-fill white → transparent (+ gate).
 *    • JPEG on white ................... SKIP → manual  (re-upload as PNG/WebP).
 *    • everything else (photos) ........ untouched, listed as "photo/complex, no action".
 *
 * --dry-run does all the work in memory (so the report is accurate) but writes
 * nothing. The real run overwrites in place, preserving the file's own format.
 */
class FixLogos extends Command
{
    protected $signature = 'images:fix-logos {--dry-run : Compute + report every decision without writing any file}';

    protected $description = 'Repair brand + sub-type logos to real transparency (checkerboard keying / white background removal), in place.';

    private array $folders = ['Brand', 'Sub_type'];

    // Confidence band for an automated key-out: the transparent area afterwards
    // must be neither trivially small (flood missed the background) nor almost
    // everything (flood ate the artwork).
    private const MIN_TRANSPARENT = 0.12;
    private const MAX_TRANSPARENT = 0.90;

    // Above this distinct-colour count a NO_ALPHA image is treated as a photo.
    private const PHOTO_COMPLEXITY = 300;

    public function handle(LogoAnalyzer $analyzer): int
    {
        if (! extension_loaded('imagick')) {
            $this->error('images:fix-logos requires the Imagick PHP extension. Run it on the server.');
            return self::FAILURE;
        }

        // Determinism (Issue 3 bug fix): force single-threaded ImageMagick so the
        // flood fill is bit-identical every run. With multiple OpenMP threads a
        // borderline flood can flip between "background only" and "whole image"
        // from run to run — that is exactly why the dry run and the real run
        // disagreed. Combined with the FIXED flood target in tryKeyOut(), the
        // command is now fully deterministic: dry-run == real-run, always.
        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1);
        } catch (\Throwable $e) {
            // Older Imagick without this limit — the fixed-target keying below is
            // still deterministic on a single-threaded build.
        }

        $dry = (bool) $this->option('dry-run');
        $this->line('<info>' . ($dry ? '[DRY RUN] ' : '') . 'images:fix-logos — repairing logos to real transparency</info>');
        $this->newLine();

        $rows   = [];
        $manual = [];
        $fixed  = 0;
        $skip   = 0;
        $untouched = 0;

        foreach ($this->listFiles() as $path) {
            $rel = $this->rel($path);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            try {
                $a = $analyzer->analyze($path);
            } catch (\Throwable $e) {
                $manual[] = "$rel — unreadable: " . $e->getMessage();
                $skip++;
                $rows[] = [$rel, 'ERROR', 'skip → manual', 'unreadable'];
                continue;
            }

            $verdict = $a['checker'] ? 'CHECKERBOARD' : ($a['has_alpha'] ? 'OK' : 'NO_ALPHA');

            // ── OK ────────────────────────────────────────────────────────────
            if ($verdict === 'OK') {
                $untouched++;
                $rows[] = [$rel, 'OK', 'none', 'already has alpha'];
                continue;
            }

            // ── CHECKERBOARD ──────────────────────────────────────────────────
            if ($verdict === 'CHECKERBOARD') {
                if ($a['has_alpha']) {
                    $manual[] = "$rel — real alpha + baked checkerboard (highest risk)";
                    $skip++;
                    $rows[] = [$rel, 'CHECKERBOARD+alpha', 'skip → manual', 'too risky to automate'];
                    continue;
                }
                if (in_array($ext, ['jpg', 'jpeg'], true)) {
                    $manual[] = "$rel — checkerboard in a JPEG (JPEG can't store alpha)";
                    $skip++;
                    $rows[] = [$rel, 'CHECKERBOARD', 'skip → manual', 'JPEG — re-upload PNG/WebP'];
                    continue;
                }
                if ($a['checker_grays'] === null) {
                    $manual[] = "$rel — checkerboard greys not resolvable";
                    $skip++;
                    $rows[] = [$rel, 'CHECKERBOARD', 'skip → manual', 'greys unresolved'];
                    continue;
                }
                // Fixed midpoint-grey target + symmetric fuzz that just spans the
                // two checker greys (plus a small margin). A fixed target — not a
                // colour sampled from the half-keyed image — keeps this
                // deterministic and stops the flood bleeding into the artwork.
                $light = $a['checker_grays'][0];
                $dark  = $a['checker_grays'][1];
                $mid   = intdiv($light + $dark, 2);
                $frac  = (($light - $dark) / 2 + 12) / 255.0;
                [$ok, $tf] = $this->tryKeyOut($path, $ext, [$mid, $mid, $mid], $frac, $dry);
                if ($ok) {
                    $fixed++;
                    $rows[] = [$rel, 'CHECKERBOARD', $dry ? 'WOULD-FIX (keyed)' : 'FIXED (keyed)', sprintf('transparent %.0f%%', $tf * 100)];
                } else {
                    $manual[] = sprintf('%s — checkerboard key-out low confidence (transparent %.0f%%)', $rel, $tf * 100);
                    $skip++;
                    $rows[] = [$rel, 'CHECKERBOARD', 'skip → manual', sprintf('low confidence %.0f%%', $tf * 100)];
                }
                continue;
            }

            // ── NO_ALPHA ──────────────────────────────────────────────────────
            $isPhoto = ! $a['corner_uniform_white'] || $a['complexity'] > self::PHOTO_COMPLEXITY;
            if ($isPhoto) {
                $untouched++;
                $rows[] = [$rel, 'NO_ALPHA', 'none', 'photo/complex — no action'];
                continue;
            }
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $manual[] = "$rel — logo on white in a JPEG (re-upload as PNG/WebP for transparency)";
                $skip++;
                $rows[] = [$rel, 'NO_ALPHA', 'skip → manual', 'JPEG — re-upload PNG/WebP'];
                continue;
            }
            // logo on a solid near-white background → key white to transparent
            [$ok, $tf] = $this->tryKeyOut($path, $ext, [255, 255, 255], 28 / 255.0, $dry);
            if ($ok) {
                $fixed++;
                $rows[] = [$rel, 'NO_ALPHA', $dry ? 'WOULD-FIX (white→α)' : 'FIXED (white→α)', sprintf('transparent %.0f%%', $tf * 100)];
            } else {
                $manual[] = sprintf('%s — white key-out low confidence (transparent %.0f%%)', $rel, $tf * 100);
                $skip++;
                $rows[] = [$rel, 'NO_ALPHA', 'skip → manual', sprintf('low confidence %.0f%%', $tf * 100)];
            }
        }

        if (empty($rows)) {
            $this->warn('No logo files found under Uploads_Images/Brand or /Sub_type.');
            return self::SUCCESS;
        }

        $this->table(['file', 'verdict', 'action', 'result'], $rows);

        $this->newLine();
        if (! empty($manual)) {
            $this->line('<comment>── Needs manual re-upload / review ──</comment>');
            foreach ($manual as $m) {
                $this->line('  • ' . $m);
            }
            $this->newLine();
        }

        $this->info(sprintf(
            '%sSummary — %d %s, %d skipped-manual, %d untouched, %d total.',
            $dry ? '[DRY RUN] ' : '',
            $fixed,
            $dry ? 'would-fix' : 'fixed',
            $skip,
            $untouched,
            count($rows),
        ));

        return self::SUCCESS;
    }

    /**
     * Key edge-reachable background to transparency with Imagick and measure the
     * resulting transparent fraction. Writes in place only when confident and not
     * a dry run. Returns [accepted, transparentFraction].
     *
     * @return array{0:bool,1:float}
     */
    private function tryKeyOut(string $path, string $ext, array $targetRgb, float $fuzzFraction, bool $dry): array
    {
        try {
            $im = new \Imagick();
            $im->readImageBlob((string) @file_get_contents($path));   // blob read → weird filenames safe
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $qr = $im->getQuantumRange()['quantumRangeLong'];
            $fuzz = $fuzzFraction * $qr;
            $fill = new \ImagickPixel('transparent');

            // FIXED target colour from the analysis (checker midpoint-grey, or
            // white) — deliberately NOT sampled from the image, which mutates as
            // we flood: a sampled seed could read 'transparent' after an earlier
            // flood and then match/eat the dark artwork, driving results toward
            // 100%. floodFillPaintImage fills only pixels matching $target within
            // $fuzz that are connected to the seed, so a seed landing on artwork
            // fills nothing. Fixed target ⇒ deterministic and artwork-safe.
            $target = new \ImagickPixel(sprintf('rgb(%d,%d,%d)', $targetRgb[0], $targetRgb[1], $targetRgb[2]));

            // Seed from every edge midpoint + corner so the whole edge-connected
            // background is caught while interior artwork is preserved.
            $seeds = [
                [0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1],
                [intdiv($w, 2), 0], [intdiv($w, 2), $h - 1],
                [0, intdiv($h, 2)], [$w - 1, intdiv($h, 2)],
            ];
            foreach ($seeds as [$x, $y]) {
                try {
                    $im->floodFillPaintImage($fill, $fuzz, $target, $x, $y, false);
                } catch (\Throwable $e) {
                    // one bad seed shouldn't abort the whole file
                }
            }

            $tf = $this->transparentFraction($im, $w, $h);
            $accept = $tf >= self::MIN_TRANSPARENT && $tf <= self::MAX_TRANSPARENT;

            if ($accept && ! $dry) {
                $im->setImageFormat($ext === 'jpg' ? 'jpeg' : $ext);
                if (in_array($ext, ['webp', 'png'], true)) {
                    $im->setImageCompressionQuality(90);
                }
                // Blob write → in place, safe for spaces/parens/mojibake names.
                file_put_contents($path, $im->getImageBlob());
            }

            $im->clear();
            return [$accept, $tf];
        } catch (\Throwable $e) {
            return [false, 0.0];
        }
    }

    private function transparentFraction(\Imagick $im, int $w, int $h): float
    {
        try {
            $alphas = $im->exportImagePixels(0, 0, $w, $h, 'A', \Imagick::PIXEL_CHAR);
        } catch (\Throwable $e) {
            return 0.0;
        }
        $n = count($alphas);
        if ($n === 0) {
            return 0.0;
        }
        $t = 0;
        foreach ($alphas as $al) {
            if ($al < 16) {   // near-fully-transparent
                $t++;
            }
        }
        return $t / $n;
    }

    /** List logo files with scandir (robust to spaces/parens/mojibake names). */
    private function listFiles(): array
    {
        $files = [];
        foreach ($this->folders as $folder) {
            $dir = public_path('Uploads_Images/' . $folder);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) && preg_match('/\.(webp|png|jpe?g|gif|avif)$/i', $name)) {
                    $files[] = $path;
                }
            }
        }
        return $files;
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace(public_path(), '', $path), '\\/');
    }
}
