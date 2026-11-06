<?php

namespace App\Domain\Media;

use GdImage;

/**
 * THE BACKGROUND MASK OF A PRODUCT PHOTO (the developer's review, 2026-11-05, item 32).
 *
 * ── What it is for ───────────────────────────────────────────────────────────────────────────
 *
 * A home slider may have its own colour, and "blend" lets the white studio background of its product
 * photos give way to that colour. The first cut did it with `mix-blend-mode: multiply`, which multiplies
 * EVERY pixel by the colour: on beige the watches themselves turned beige. The rule now: the blend may
 * only replace the photo's white background; the product's own pixels stay exactly as they are.
 *
 * ── How ──────────────────────────────────────────────────────────────────────────────────────
 *
 * The photo is not touched, re-encoded or replaced. This class reads it ONCE and produces a small
 * transparency MASK of its background (a PNG, {@see self::SIZE} px on its longer side): transparent
 * where the studio white is, opaque where the product is. The storefront draws the SAME photo file it
 * always drew and applies the mask with CSS (`mask-image`), so the section's colour shows where the
 * white was and every product pixel the browser paints is the photo's own — by construction, not by
 * tuning. The mask travels inside the page's data (a `data:` URL, about 2 KB), so there is no second
 * image request, no cross-origin rule to satisfy (a CSS mask from another host needs CORS, and a mask
 * that fails to load hides the whole photo) and no file on disk to keep in step with its master.
 *
 * ── What counts as background ────────────────────────────────────────────────────────────────
 *
 * Only near-white, neutral pixels CONNECTED TO THE EDGE of the photo (a flood from the border,
 * four-connected). White INSIDE the product — a white dial, a white shirt, a highlight — is not
 * reached by the flood and is never made transparent. Two consequences, both deliberate:
 *
 *  • a white area ENCLOSED by the product (the gap inside a bag's handle) stays white: telling it from
 *    a white dial is a guess, and a wrong guess punches a hole in a product;
 *  • a soft studio SHADOW under the product stays: it is light neutral grey, and so is a steel
 *    bracelet — making light grey transparent would tint steel.
 *
 * The one-pixel ring where the product meets the background is anti-aliased with white in the photo
 * itself; those pixels get a partial opacity from how dark they are, so the edge is soft instead of a
 * white outline. Nothing inside that ring is ever less than fully opaque.
 *
 * A photo with no studio background to speak of (a lifestyle picture, or one that fills its frame) has
 * NO mask: {@see self::fromFile()} returns null, and the storefront shows that photo as it is.
 */
final class CutoutMask
{
    /** The mask's longer side, in pixels. A card's photo is at most ~270 CSS px wide; the browser scales the mask smoothly. */
    public const SIZE = 256;

    /** A background pixel: every channel at least this… */
    private const WHITE_MIN = 238;

    /** …and no more than this apart (neutral — a pale yellow or pink product is not background). */
    private const NEUTRAL_SPREAD = 14;

    /** An edge pixel is fully opaque once its darkest channel is this far below white. */
    private const EDGE_RAMP = 56;

    /** Below this share of background there is nothing to blend; above {@see self::MAX_SHARE} the photo is blank. */
    private const MIN_SHARE = 0.04;

    private const MAX_SHARE = 0.97;

    /**
     * @return array{png: string, width: int, height: int, background: float}|null null: unreadable, or no studio background
     */
    public static function fromFile(string $path): ?array
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }
        $source = @imagecreatefromstring($data);
        unset($data);
        if ($source === false) {
            return null;
        }
        $out = self::fromImage($source);
        imagedestroy($source);

        return $out;
    }

    /**
     * @return array{png: string, width: int, height: int, background: float}|null
     */
    public static function fromImage(GdImage $source): ?array
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        if ($sw < 8 || $sh < 8) {
            return null;
        }
        $ratio = self::SIZE / max($sw, $sh);
        $w = max(8, (int) round($sw * $ratio));
        $h = max(8, (int) round($sh * $ratio));

        // the photo at the mask's size, flattened onto white (a transparent PNG upload reads as its white pad)
        $small = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($small, 255, 255, 255);
        imagefill($small, 0, 0, $white === false ? 0xFFFFFF : $white);
        imagecopyresampled($small, $source, 0, 0, 0, 0, $w, $h, $sw, $sh);

        // per pixel: its darkest channel, and whether it could be background (near-white and neutral)
        $n = $w * $h;
        $dark = str_repeat("\0", $n);
        $candidate = str_repeat("\0", $n);
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                $rgb = (int) imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $min = min($r, $g, $b);
                $dark[$i] = chr($min);
                if ($min >= self::WHITE_MIN && max($r, $g, $b) - $min <= self::NEUTRAL_SPREAD) {
                    $candidate[$i] = "\1";
                }
            }
        }
        imagedestroy($small);

        // the flood: from every border pixel that is a candidate, through candidates, four-connected
        $background = str_repeat("\0", $n);
        $stack = [];
        for ($x = 0; $x < $w; $x++) {
            $stack[] = $x;
            $stack[] = ($h - 1) * $w + $x;
        }
        for ($y = 0; $y < $h; $y++) {
            $stack[] = $y * $w;
            $stack[] = $y * $w + $w - 1;
        }
        $count = 0;
        while ($stack !== []) {
            $i = array_pop($stack);
            if ($candidate[$i] !== "\1" || $background[$i] === "\1") {
                continue;
            }
            $background[$i] = "\1";
            $count++;
            $x = $i % $w;
            if ($x > 0) {
                $stack[] = $i - 1;
            }
            if ($x < $w - 1) {
                $stack[] = $i + 1;
            }
            if ($i >= $w) {
                $stack[] = $i - $w;
            }
            if ($i < $n - $w) {
                $stack[] = $i + $w;
            }
        }
        $share = $count / $n;
        if ($share < self::MIN_SHARE || $share > self::MAX_SHARE) {
            return null;
        }

        // the mask: transparent background, opaque product, a soft ring where they meet
        $mask = imagecreatetruecolor($w, $h);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        $clear = (int) imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagefill($mask, 0, 0, $clear);
        /** @var array<int, int> $ink GD alpha (0 opaque … 127 transparent) => colour index */
        $ink = [];
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                if ($background[$i] === "\1") {
                    continue;
                }
                $opacity = 255;
                $touches = ($x > 0 && $background[$i - 1] === "\1") || ($x < $w - 1 && $background[$i + 1] === "\1")
                    || ($y > 0 && $background[$i - $w] === "\1") || ($y < $h - 1 && $background[$i + $w] === "\1");
                if ($touches) {
                    $opacity = (int) min(255, max(48, round((255 - ord($dark[$i])) * 255 / self::EDGE_RAMP)));
                }
                // GD's alpha runs the other way and on seven bits: 0 is opaque, 127 transparent
                $alpha = max(0, min(127, 127 - ($opacity >> 1)));
                $ink[$alpha] ??= (int) imagecolorallocatealpha($mask, 0, 0, 0, $alpha);
                imagesetpixel($mask, $x, $y, $ink[$alpha]);
            }
        }

        ob_start();
        $ok = imagepng($mask, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($mask);
        if (! $ok || $png === '') {
            return null;
        }

        return ['png' => $png, 'width' => $w, 'height' => $h, 'background' => round($share, 4)];
    }

    /** The mask as the storefront uses it: a `data:` URL for a CSS `mask-image`. */
    public static function dataUrl(string $png): string
    {
        return 'data:image/png;base64,'.base64_encode($png);
    }
}
