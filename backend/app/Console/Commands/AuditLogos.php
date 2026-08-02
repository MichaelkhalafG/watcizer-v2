<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Issue 3B — audit brand + sub-type logos for transparency problems.
 *
 * STRICTLY READ-ONLY: it opens each file, samples pixels in memory, and reports.
 * It never writes, deletes, creates, or moves anything, so it is safe to run on
 * production before we design the fix-logos repair.
 *
 * Per file it decides one verdict:
 *   CHECKERBOARD_DETECTED — a transparency-preview checkerboard is baked into
 *                           the pixels (two alternating light greys). This is
 *                           the "TUDOR / sports" symptom.
 *   NO_ALPHA             — the image is fully opaque (no real transparency).
 *   OK                   — the image carries genuine alpha and looks clean.
 */
class AuditLogos extends Command
{
    protected $signature = 'images:audit-logos';

    protected $description = 'Read-only audit of brand + sub-type logos: driver/WebP-alpha capabilities, per-file transparency verdict.';

    /** Folders under public/Uploads_Images that hold logos. */
    private array $folders = ['Brand', 'Sub_type'];

    public function handle(): int
    {
        $this->printHeader();

        $rows    = [];
        $counts  = ['OK' => 0, 'NO_ALPHA' => 0, 'CHECKERBOARD_DETECTED' => 0, 'ERROR' => 0];
        $manager = new ImageManager(new Driver());

        foreach ($this->folders as $folder) {
            $dir = public_path('Uploads_Images/' . $folder);
            if (! is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '/*') as $path) {
                if (! is_file($path) || ! preg_match('/\.(webp|png|jpe?g|gif|avif)$/i', $path)) {
                    continue;
                }
                $rel = $folder . '/' . basename($path);
                try {
                    $image = $manager->read($path);
                    $w     = $image->width();
                    $h     = $image->height();
                    $gd    = $image->core()->native();

                    if ($gd instanceof \GdImage && ! imageistruecolor($gd)) {
                        imagepalettetotruecolor($gd);
                    }

                    $hasAlpha = ($gd instanceof \GdImage) ? $this->hasRealAlpha($gd, $w, $h) : false;
                    $checker  = ($gd instanceof \GdImage) ? $this->isCheckerboard($gd, $w, $h) : false;

                    $verdict = $checker ? 'CHECKERBOARD_DETECTED' : ($hasAlpha ? 'OK' : 'NO_ALPHA');
                    $counts[$verdict]++;

                    $rows[] = [
                        $rel,
                        strtoupper(pathinfo($path, PATHINFO_EXTENSION)),
                        $w . 'x' . $h,
                        $hasAlpha ? 'yes' : 'no',
                        $checker ? 'YES' : 'no',
                        $verdict,
                    ];
                } catch (\Throwable $e) {
                    $counts['ERROR']++;
                    $rows[] = [$rel, '?', '?', '?', '?', 'ERROR: ' . $e->getMessage()];
                }
            }
        }

        if (empty($rows)) {
            $this->warn('No logo files found under Uploads_Images/Brand or /Sub_type.');
            return self::SUCCESS;
        }

        $this->table(
            ['file', 'format', 'dims', 'real-alpha?', 'checkerboard?', 'verdict'],
            $rows,
        );

        $this->newLine();
        $this->info(sprintf(
            'Summary — %d OK, %d NO_ALPHA, %d CHECKERBOARD_DETECTED, %d ERROR, %d total.',
            $counts['OK'],
            $counts['NO_ALPHA'],
            $counts['CHECKERBOARD_DETECTED'],
            $counts['ERROR'],
            count($rows),
        ));

        return self::SUCCESS;
    }

    /** Print the environment/driver capability header. */
    private function printHeader(): void
    {
        $imagickLoaded = extension_loaded('imagick');
        $gdLoaded      = extension_loaded('gd');

        // Active driver is whatever ImageService instantiates — currently GD,
        // hardcoded (Intervention\Image\Drivers\Gd\Driver).
        $activeDriver = 'GD (Intervention\\Image\\Drivers\\Gd\\Driver — hardcoded in ImageService)';

        // WebP-with-alpha capability of each driver.
        $gdWebp = function_exists('imagewebp')
            ? 'yes (imagewebp present; alpha preserved when imagesavealpha is set)'
            : 'NO (imagewebp missing — GD built without WebP)';

        $imagickWebp = 'n/a (extension not loaded)';
        if ($imagickLoaded) {
            try {
                $imagickWebp = ! empty(\Imagick::queryFormats('WEBP'))
                    ? 'yes (Imagick supports WEBP; alpha preserved natively)'
                    : 'NO (Imagick built without WEBP)';
            } catch (\Throwable $e) {
                $imagickWebp = 'unknown (' . $e->getMessage() . ')';
            }
        }

        $this->line('<info>══ Logo audit — environment ══</info>');
        $this->line('  Active ImageService driver : ' . $activeDriver);
        $this->line('  imagick extension loaded   : ' . ($imagickLoaded ? 'yes' : 'no'));
        $this->line('  gd extension loaded        : ' . ($gdLoaded ? 'yes' : 'no'));
        $this->line('  WebP+alpha (GD)            : ' . $gdWebp);
        $this->line('  WebP+alpha (Imagick)       : ' . $imagickWebp);
        $this->newLine();
    }

    /**
     * True if any sampled pixel is non-opaque (real transparency present).
     * GD alpha: 0 = opaque … 127 = fully transparent. Sampled on a grid (≈120²)
     * so it is fast even on large logos; a logo's transparent background is far
     * larger than the sampling step, so it is reliably caught.
     */
    private function hasRealAlpha(\GdImage $gd, int $w, int $h): bool
    {
        $stepX = max(1, intdiv($w, 120));
        $stepY = max(1, intdiv($h, 120));
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $alpha = (imagecolorat($gd, $x, $y) >> 24) & 0x7F;
                if ($alpha > 8) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Heuristic: detect a baked-in transparency checkerboard — a regular
     * alternation of two light greys. Samples several rows and columns; if a
     * good fraction of them alternate between two distinct light-grey clusters
     * with many transitions, it's a checkerboard.
     */
    private function isCheckerboard(\GdImage $gd, int $w, int $h): bool
    {
        $lines   = [];
        foreach ([0.06, 0.25, 0.5, 0.75, 0.94] as $f) {
            $lines[] = $this->readRow($gd, $w, (int) ($h * $f));
            $lines[] = $this->readCol($gd, $h, (int) ($w * $f));
        }

        $checkerLines = 0;
        $usable       = 0;
        foreach ($lines as $line) {
            if (count($line) < 20) {
                continue;
            }
            $usable++;
            if ($this->lineLooksCheckered($line)) {
                $checkerLines++;
            }
        }

        return $usable > 0 && ($checkerLines / $usable) >= 0.4;
    }

    /** @return array<int,array{0:int,1:int,2:int}> */
    private function readRow(\GdImage $gd, int $w, int $y): array
    {
        $y = max(0, min($h = imagesy($gd) - 1, $y));
        $out = [];
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($gd, $x, $y);
            $out[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        }
        return $out;
    }

    /** @return array<int,array{0:int,1:int,2:int}> */
    private function readCol(\GdImage $gd, int $h, int $x): array
    {
        $x = max(0, min($w = imagesx($gd) - 1, $x));
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            $c = imagecolorat($gd, $x, $y);
            $out[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        }
        return $out;
    }

    /**
     * A line "looks checkered" when it is mostly light grey, splits into two
     * distinct light-grey luminance clusters, and flips between them often.
     *
     * @param array<int,array{0:int,1:int,2:int}> $pixels
     */
    private function lineLooksCheckered(array $pixels): bool
    {
        $n     = count($pixels);
        $lum   = [];
        $grays = 0;

        foreach ($pixels as [$r, $g, $b]) {
            if (abs($r - $g) < 16 && abs($g - $b) < 16 && abs($r - $b) < 16) {
                $v = (int) round(($r + $g + $b) / 3);
                if ($v >= 150) {          // light grey / white only
                    $lum[] = $v;
                    $grays++;
                    continue;
                }
            }
            $lum[] = null;                // coloured / dark → breaks the pattern
        }

        if ($grays < $n * 0.7) {
            return false;                 // not predominantly a light-grey field
        }

        // Two dominant luminance buckets among the grey pixels.
        $hist = [];
        foreach ($lum as $v) {
            if ($v !== null) {
                $bucket = intdiv($v, 8) * 8;
                $hist[$bucket] = ($hist[$bucket] ?? 0) + 1;
            }
        }
        arsort($hist);
        $tops = array_slice(array_keys($hist), 0, 2);
        if (count($tops) < 2) {
            return false;                 // a single flat grey → not a checker
        }
        [$a, $b] = $tops;
        $diff = abs($a - $b);
        if ($diff < 16 || $diff > 96) {
            return false;                 // clusters must be two distinct LIGHT greys
        }

        // Count how often the nearest-cluster assignment flips along the line.
        $trans = 0;
        $prev  = null;
        foreach ($lum as $v) {
            if ($v === null) {
                $prev = null;
                continue;
            }
            $cl = abs($v - $a) <= abs($v - $b) ? 0 : 1;
            if ($prev !== null && $cl !== $prev) {
                $trans++;
            }
            $prev = $cl;
        }

        // A real checker flips every cell (< ~40px), so many transitions.
        return $trans >= max(4, intdiv($n, 40));
    }
}
