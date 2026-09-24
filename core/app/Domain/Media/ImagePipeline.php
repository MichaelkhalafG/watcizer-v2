<?php

namespace App\Domain\Media;

use GdImage;
use RuntimeException;

/**
 * Master + renditions, with raw GD (CLEAN_CORE_STUDY §5.4).
 *
 * Raw GD rather than a package: the legacy app uses intervention/image, but core needs exactly two
 * operations — scale-down-never-up and pad-onto-a-square — and doing them by hand keeps the
 * dependency surface of a public repo smaller and the behaviour identical to the presets the legacy
 * `ImageService` already produces.
 *
 * **Never upscales.** `scaleDown` semantics: an 800 px source asked for a 1200 px master stays
 * 800 px of PICTURE, padded onto a 1200 square when the preset says so — which is how the legacy
 * presets keep a grid of product cards from jumping. The reported resolution is the picture's,
 * not the canvas's, and a rendition wider than the picture is skipped rather than blown up
 * (D-16: it used to compare against the padded canvas, so for a product image the skip could
 * never fire and a 960 px rendition was produced from an 800 px photo and labelled 960).
 *
 * Alpha is preserved end to end, except where a preset pads onto white — a watch on transparency
 * padded onto white is the legacy behaviour and the storefront depends on it.
 */
final class ImagePipeline
{
    /**
     * Marks an entry in `skipped` as a real FAILURE rather than a deliberate omission.
     *
     * The list holds both, and the difference matters to everything downstream. *"320w: source is
     * only 300px wide"* is the pipeline refusing to upscale — correct, expected, and true of a large
     * share of a supplier catalogue; flagging those products would put half the shop on a review
     * list. *"the encoder reported success but wrote 0 bytes"* is something going wrong that nobody
     * asked for, and it is the one a person has to see.
     *
     * A prefix rather than a second array because `skipped` is already persisted and already read by
     * the upload screen; splitting the shape would mean changing every reader to fix one of them.
     */
    public const FAILED_PREFIX = 'FAILED ';

    /** Did this pipeline result carry a real failure (as opposed to a deliberate skip)? */
    public static function hasFailure(mixed $skipped): bool
    {
        if (! is_array($skipped)) {
            return false;
        }

        foreach ($skipped as $entry) {
            if (is_string($entry) && str_starts_with($entry, self::FAILED_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{folder: string, master: array{width: int, height: int, quality: int, pad_square: bool}, widths: list<int>, thumbnails: list<int>}  $config
     * @return array{width: int, height: int, bytes: int, renditions: array<int, array<string, string>>, skipped: list<string>}
     */
    public function write(string $sourcePath, string $masterPath, array $config): array
    {
        if (! MediaCapabilities::hasGd()) {
            throw new RuntimeException('GD is not available: this host cannot process images.');
        }
        if (! MediaCapabilities::canWriteWebp()) {
            throw new RuntimeException('GD cannot write WebP on this host, and WebP is the master format (§5.4).');
        }

        $source = $this->read($sourcePath);

        /*
         * ── The PHOTOGRAPH's size, not the canvas's (D-16, 2026-09-19) ──────────────────────
         *
         * An 800 x 800 upload was reported on screen as `1200x1200` with renditions
         * `960 - 640 - 480 - 320 - 160`, and the 960 and the 1200 were padding. The `product`
         * preset is `pad_square`, so `fit()` creates the canvas at exactly the preset's size and
         * centres the source on white — 44% of that master is white, measured: the photograph
         * starts at x = 200.
         *
         * Everything downstream then measured the CANVAS. The screen told the operator they had
         * uploaded a 1200 px image when they had uploaded an 800 px one, and the rendition guard
         * compared each target against the padded width, so its *"source is only Npx wide"*
         * message could never fire for `product` or `product_gallery` — the one case it exists
         * for. The class docblock's own example ("an 800 px source asked for a 1200 px master
         * stays 800") described behaviour the padding path did not have.
         *
         * `$detail` is how much of the master is actual picture: the source scaled down to fit,
         * never up. For a padded 800 px source that is 800; for a 4,000 px source into a 1,200 px
         * master it is 1,200. Both are the honest answer to "what resolution is this image", and
         * both are the right number to decide whether a rendition would be inventing detail.
         */
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $ratio = min(
            $config['master']['width'] / max(1, $sourceWidth),
            $config['master']['height'] / max(1, $sourceHeight),
            1.0,
        );
        $detailWidth = max(1, (int) round($sourceWidth * $ratio));
        $detailHeight = max(1, (int) round($sourceHeight * $ratio));

        $master = $this->fit($source, $config['master']['width'], $config['master']['height'], $config['master']['pad_square']);
        imagedestroy($source);

        /*
         * The master gets the same bytes-on-disk check as the renditions below, and it THROWS rather
         * than recording a failure. A missing rendition degrades — the loader falls through to
         * another source. A zero-byte master is the image itself, so there is nothing to fall
         * through to, and a caller that carried on would attach a broken file to a product and call
         * it done.
         */
        if (! imagewebp($master, $masterPath, $config['master']['quality']) || (int) (@filesize($masterPath) ?: 0) === 0) {
            imagedestroy($master);
            if (is_file($masterPath)) {
                @unlink($masterPath);
            }
            throw new RuntimeException("Could not write the master image to [{$masterPath}]: the encoder produced no bytes.");
        }

        // The CANVAS, for resizing the renditions — they have to match the master's geometry or
        // a padded image would come back cropped.
        $width = imagesx($master);
        $height = imagesy($master);

        // Keyed by WIDTH, and the keys are INTS: PHP casts a numeric array key to one whatever
        // the code writes. `json_encode` turns them back into object keys, which is why the
        // TypeScript side reads `Record<string, …>`.
        /** @var array<int, array<string, string>> $renditions */
        $renditions = [];
        /** @var list<string> $skipped */
        $skipped = [];
        $formats = MediaCapabilities::renditionFormats();
        if (! MediaCapabilities::canWriteAvif()) {
            // Loud, and recorded with the file rather than only in a log: the frontend loader must
            // not advertise an AVIF that was never written.
            $skipped[] = 'avif: GD on this host cannot write it';
        }

        $base = preg_replace('/\.webp$/', '', $masterPath) ?? $masterPath;
        $targets = array_values(array_unique(array_merge($config['widths'], $config['thumbnails'])));
        sort($targets);
        foreach ($targets as $target) {
            if ($target >= $detailWidth) {
                // Wider than the PICTURE inside the master: upscaling would invent detail and cost
                // bytes. Measured against the photograph, not the padded canvas (D-16) — against
                // the canvas this branch was unreachable for every `pad_square` preset, which is
                // every product image.
                $skipped[] = "{$target}w: source is only {$detailWidth}px wide";

                continue;
            }
            $resized = $this->fit($master, $target, (int) round($target * $height / $width), false);
            foreach ($formats as $format) {
                $path = $base.'-'.$target.'.'.$format;
                $ok = $format === 'avif'
                    ? imageavif($resized, $path, config()->integer('media.renditions.avif_quality'))
                    : imagewebp($resized, $path, config()->integer('media.renditions.webp_quality'));

                /*
                 * ── `true` IS NOT ENOUGH. The file has to have bytes in it (2026-09-17) ────────
                 *
                 * GD's encoders can return `true` and leave a ZERO-BYTE file behind — measured on
                 * this host: 8 empty renditions across 59 835 files, 7 of them AVIF, spread over the
                 * 320/480/640/960 sizes with no pattern. The old code trusted the return value, so
                 * the empty file was recorded in `renditions` and served: an empty AVIF usually
                 * falls through to the next `<picture>` source, but an empty WebP is a broken image
                 * in front of a customer, and nothing anywhere noticed either.
                 *
                 * So the file is REMOVED and not recorded. A rendition that does not exist is a
                 * rendition the loader never advertises, which is exactly the rule the `skipped`
                 * list already exists to keep — and `FAILED_PREFIX` marks it as a real failure
                 * rather than one of the deliberate skips beside it.
                 */
                $bytes = $ok ? (int) (@filesize($path) ?: 0) : 0;
                if ($ok && $bytes > 0) {
                    $renditions[$target][$format] = basename($path);

                    continue;
                }

                if (is_file($path)) {
                    @unlink($path);
                }
                $skipped[] = self::FAILED_PREFIX."{$target}w {$format}: the encoder reported "
                    .($ok ? 'success but wrote 0 bytes' : 'failure');
            }
            imagedestroy($resized);
        }

        $bytes = (int) (filesize($masterPath) ?: 0);
        imagedestroy($master);

        return [
            // Reported as the PICTURE's resolution, which is what the operator uploaded and what
            // they are being asked to judge. The file on disk is the padded canvas; its size is
            // an implementation detail of the preset and tells nobody anything actionable.
            'width' => $detailWidth,
            'height' => $detailHeight,
            'bytes' => $bytes,
            'renditions' => $renditions,
            'skipped' => $skipped,
        ];
    }

    /**
     * Decode whatever the browser sent; the extension is not trusted, the bytes are.
     *
     * Both refusals here are about the FILE, not the host, so they throw {@see UnreadableUpload}
     * and the endpoint answers 422 (review 🟡-5). A host fault keeps throwing plain
     * `RuntimeException` and keeps its 500.
     */
    private function read(string $path): GdImage
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            throw new UnreadableUpload("Cannot read the uploaded file at [{$path}].");
        }
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw new UnreadableUpload('The uploaded file is not an image GD can decode.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Scale DOWN to fit (never up), then optionally pad onto a white square of exactly WxH —
     * the legacy `pad_square` preset.
     */
    private function fit(GdImage $source, int $maxWidth, int $maxHeight, bool $padSquare): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $ratio = min($maxWidth / $sw, $maxHeight / $sh, 1.0);
        $tw = max(1, (int) round($sw * $ratio));
        $th = max(1, (int) round($sh * $ratio));

        $canvasWidth = $padSquare ? $maxWidth : $tw;
        $canvasHeight = $padSquare ? $maxHeight : $th;

        $canvas = imagecreatetruecolor(max(1, $canvasWidth), max(1, $canvasHeight));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if ($padSquare) {
            // White, opaque — the legacy pad colour. A padded image is never transparent.
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white === false ? 0 : $white);
        } else {
            $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $clear === false ? 0 : $clear);
        }

        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas, $source,
            (int) round(($canvasWidth - $tw) / 2), (int) round(($canvasHeight - $th) / 2),
            0, 0, $tw, $th, $sw, $sh,
        );
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        return $canvas;
    }
}
