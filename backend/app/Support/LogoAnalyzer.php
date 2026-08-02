<?php

namespace App\Support;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Read-only pixel analysis for logo images (brand + sub-type).
 *
 * Shares the exact transparency/checkerboard heuristics used by the (already
 * validated) images:audit-logos command, plus the extra signals images:fix-logos
 * needs to decide a repair safely: the two checkerboard greys (to size the flood
 * fill), whether the border is a uniform near-white field (logo-on-white vs a
 * photo), and a colour-complexity estimate (photo detection).
 *
 * Decode path mirrors the audit (Intervention GD driver → native GdImage) so the
 * verdicts stay identical to the audit output. NEVER writes to disk.
 */
class LogoAnalyzer
{
    /**
     * @return array{
     *   w:int, h:int, has_alpha:bool, checker:bool,
     *   checker_grays:?array{0:int,1:int}, corner_uniform_white:bool, complexity:int
     * }
     */
    public function analyze(string $path): array
    {
        $manager = new ImageManager(new Driver());
        $image   = $manager->read($path);
        $w       = $image->width();
        $h       = $image->height();
        $gd      = $image->core()->native();

        if (! ($gd instanceof \GdImage)) {
            throw new \RuntimeException('Could not obtain a GD handle for analysis.');
        }
        if (! imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }

        return [
            'w'                    => $w,
            'h'                    => $h,
            'has_alpha'            => $this->hasRealAlpha($gd, $w, $h),
            'checker'              => $this->isCheckerboard($gd, $w, $h),
            'checker_grays'        => $this->checkerGrays($gd, $w, $h),
            'corner_uniform_white' => $this->cornersUniformWhite($gd, $w, $h),
            'complexity'           => $this->colourComplexity($gd, $w, $h),
        ];
    }

    public function hasRealAlpha(\GdImage $gd, int $w, int $h): bool
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

    public function isCheckerboard(\GdImage $gd, int $w, int $h): bool
    {
        $lines  = $this->sampleLines($gd, $w, $h);
        $checker = 0;
        $usable  = 0;
        foreach ($lines as $line) {
            if (count($line) < 20) {
                continue;
            }
            $usable++;
            if ($this->lineLooksCheckered($line)) {
                $checker++;
            }
        }
        return $usable > 0 && ($checker / $usable) >= 0.4;
    }

    /**
     * The two dominant light-grey luminances of the checkerboard (or null).
     *
     * @return array{0:int,1:int}|null
     */
    public function checkerGrays(\GdImage $gd, int $w, int $h): ?array
    {
        $hist = [];
        foreach ($this->sampleLines($gd, $w, $h) as $line) {
            foreach ($line as [$r, $g, $b]) {
                if (abs($r - $g) < 16 && abs($g - $b) < 16 && abs($r - $b) < 16) {
                    $v = (int) round(($r + $g + $b) / 3);
                    if ($v >= 150) {
                        $bucket = intdiv($v, 8) * 8;
                        $hist[$bucket] = ($hist[$bucket] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($hist);
        $tops = array_slice(array_keys($hist), 0, 2);
        if (count($tops) < 2) {
            return null;
        }
        $a = (int) $tops[0];
        $b = (int) $tops[1];
        $diff = abs($a - $b);
        if ($diff < 16 || $diff > 96) {
            return null;
        }
        return [max($a, $b), min($a, $b)]; // [lighter, darker]
    }

    /** All four corners are a uniform, grayish near-white field (logo on white). */
    public function cornersUniformWhite(\GdImage $gd, int $w, int $h): bool
    {
        $pts = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]];
        $lums = [];
        foreach ($pts as [$x, $y]) {
            $c = imagecolorat($gd, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            if (! (abs($r - $g) < 16 && abs($g - $b) < 16 && abs($r - $b) < 16)) {
                return false;                    // a coloured corner → not white bg
            }
            $lum = (int) round(($r + $g + $b) / 3);
            if ($lum < 238) {
                return false;                    // not near-white
            }
            $lums[] = $lum;
        }
        return (max($lums) - min($lums)) <= 16;  // corners agree
    }

    /** Distinct-colour count on a downsampled grid — photos score high, logos low. */
    public function colourComplexity(\GdImage $gd, int $w, int $h): int
    {
        $stepX = max(1, intdiv($w, 80));
        $stepY = max(1, intdiv($h, 80));
        $seen  = [];
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $c = imagecolorat($gd, $x, $y);
                // quantize to 4 bits/channel to ignore compression noise
                $key = ((($c >> 20) & 0xF) << 8) | ((($c >> 12) & 0xF) << 4) | (($c >> 4) & 0xF);
                $seen[$key] = true;
            }
        }
        return count($seen);
    }

    /** @return array<int,array<int,array{0:int,1:int,2:int}>> */
    private function sampleLines(\GdImage $gd, int $w, int $h): array
    {
        $lines = [];
        foreach ([0.06, 0.25, 0.5, 0.75, 0.94] as $f) {
            $lines[] = $this->readRow($gd, $w, $h, (int) ($h * $f));
            $lines[] = $this->readCol($gd, $w, $h, (int) ($w * $f));
        }
        return $lines;
    }

    /** @return array<int,array{0:int,1:int,2:int}> */
    private function readRow(\GdImage $gd, int $w, int $h, int $y): array
    {
        $y = max(0, min($h - 1, $y));
        $out = [];
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($gd, $x, $y);
            $out[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        }
        return $out;
    }

    /** @return array<int,array{0:int,1:int,2:int}> */
    private function readCol(\GdImage $gd, int $w, int $h, int $x): array
    {
        $x = max(0, min($w - 1, $x));
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            $c = imagecolorat($gd, $x, $y);
            $out[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        }
        return $out;
    }

    /** @param array<int,array{0:int,1:int,2:int}> $pixels */
    private function lineLooksCheckered(array $pixels): bool
    {
        $n     = count($pixels);
        $lum   = [];
        $grays = 0;
        foreach ($pixels as [$r, $g, $b]) {
            if (abs($r - $g) < 16 && abs($g - $b) < 16 && abs($r - $b) < 16) {
                $v = (int) round(($r + $g + $b) / 3);
                if ($v >= 150) {
                    $lum[] = $v;
                    $grays++;
                    continue;
                }
            }
            $lum[] = null;
        }
        if ($grays < $n * 0.7) {
            return false;
        }

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
            return false;
        }
        [$a, $b] = $tops;
        $diff = abs($a - $b);
        if ($diff < 16 || $diff > 96) {
            return false;
        }

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
        return $trans >= max(4, intdiv($n, 40));
    }
}
