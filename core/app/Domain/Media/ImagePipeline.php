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
 * 800 px (padded to a 1200 square when the preset says so, which is how the legacy presets keep a
 * grid of product cards from jumping). A rendition wider than the master is skipped rather than
 * blown up, and the skip is reported.
 *
 * Alpha is preserved end to end, except where a preset pads onto white — a watch on transparency
 * padded onto white is the legacy behaviour and the storefront depends on it.
 */
final class ImagePipeline
{
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
        $master = $this->fit($source, $config['master']['width'], $config['master']['height'], $config['master']['pad_square']);
        imagedestroy($source);

        if (! imagewebp($master, $masterPath, $config['master']['quality'])) {
            imagedestroy($master);
            throw new RuntimeException("Could not write the master image to [{$masterPath}].");
        }

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
            if ($target >= $width) {
                // Wider than the master: upscaling would invent detail and cost bytes.
                $skipped[] = "{$target}w: source is only {$width}px wide";

                continue;
            }
            $resized = $this->fit($master, $target, (int) round($target * $height / $width), false);
            foreach ($formats as $format) {
                $path = $base.'-'.$target.'.'.$format;
                $ok = $format === 'avif'
                    ? imageavif($resized, $path, config()->integer('media.renditions.avif_quality'))
                    : imagewebp($resized, $path, config()->integer('media.renditions.webp_quality'));
                if ($ok) {
                    $renditions[$target][$format] = basename($path);
                }
            }
            imagedestroy($resized);
        }

        $bytes = (int) (filesize($masterPath) ?: 0);
        imagedestroy($master);

        return [
            'width' => $width,
            'height' => $height,
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
