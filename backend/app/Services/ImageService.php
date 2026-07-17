<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Centralised image pipeline for the admin dashboard.
 *
 * Every dashboard upload is funnelled through {@see ImageService::process()}, which
 *   1. auto-orients the image using its EXIF data,
 *   2. scales it DOWN to fit a max box while preserving aspect ratio (never upscales),
 *   3. (logos only) knocks out a solid background to transparency,
 *   4. encodes to WebP at the requested quality and
 *   5. saves it under public/Uploads_Images/<folder>/ with the legacy naming scheme.
 *
 * Old (non-WebP) images already on disk are untouched and keep displaying — this
 * service only affects newly uploaded files.
 */
class ImageService
{
    /**
     * Defaults merged with the per-call $options.
     *
     * @var array<string, mixed>
     */
    protected array $defaults = [
        'max_width'   => 1200,
        'max_height'  => 1200,
        'quality'     => 85,
        'transparent' => false,
    ];

    /**
     * Process and save an uploaded image, returning the stored file name.
     *
     * @param  UploadedFile  $file    The uploaded file.
     * @param  string        $folder  Sub-folder under public/Uploads_Images (e.g. 'Product', 'Brand', 'Banner_home').
     * @param  array         $options ['max_width' => int, 'max_height' => int, 'quality' => int, 'transparent' => bool]
     * @return string                 Relative file name saved, e.g. '1700000000_2026-01-01_abc123.webp'.
     */
    public function process(UploadedFile $file, string $folder, array $options = []): string
    {
        $opt = array_merge($this->defaults, $options);

        $manager = new ImageManager(new Driver());
        $image   = $manager->read($file);

        // Respect the camera/phone orientation stored in EXIF before we resize.
        try {
            $image->orient();
        } catch (\Throwable $e) {
            // No EXIF / unsupported — safe to ignore.
        }

        // Shrink to fit the max box, keeping aspect ratio. scaleDown never enlarges.
        $image->scaleDown((int) $opt['max_width'], (int) $opt['max_height']);

        // Logos: ensure a transparent background (alpha kept as-is for PNG/WebP,
        // solid background stripped for opaque sources such as JPG).
        if (! empty($opt['transparent'])) {
            $image = $this->ensureTransparent($image, $file);
        }

        $filename = $this->makeFileName();
        $dir      = public_path('Uploads_Images/' . trim($folder, '/'));

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $image->toWebp((int) $opt['quality'])->save($dir . '/' . $filename);

        return $filename;
    }

    /**
     * Generate a file name that keeps the project's historical pattern.
     */
    protected function makeFileName(): string
    {
        return time() . '_' . date('Y-m-d') . '_' . uniqid() . '.webp';
    }

    /**
     * Guarantee a logo carries transparency.
     *
     * PNG/WebP sources already support alpha, so they pass through untouched (WebP
     * export preserves their transparency). Opaque sources (JPG/GIF) get a best-effort
     * solid-background removal; on any failure we log a warning and keep the image as-is,
     * exactly as the requirements allow.
     */
    protected function ensureTransparent(ImageInterface $image, UploadedFile $file): ImageInterface
    {
        $ext  = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getClientMimeType());

        // Alpha-capable formats: trust the uploaded transparency.
        if (in_array($ext, ['png', 'webp'], true) || in_array($mime, ['image/png', 'image/webp'], true)) {
            return $image;
        }

        try {
            return $this->removeBackground($image);
        } catch (\Throwable $e) {
            Log::warning('ImageService: logo background removal skipped', [
                'file'   => $file->getClientOriginalName(),
                'reason' => $e->getMessage(),
            ]);

            return $image;
        }
    }

    /**
     * Remove a dominant solid background from a logo by flood-filling transparency
     * inward from the borders.
     *
     * Only background regions that are contiguous with the image edge are removed, so
     * light areas *inside* the logo (e.g. a white dial) are preserved. If the four
     * corners disagree the background is deemed non-solid/ambiguous and we bail out
     * (the caller then keeps the image unchanged and logs a warning).
     *
     * @throws \RuntimeException when the background is not a dominant solid colour.
     */
    protected function removeBackground(ImageInterface $image): ImageInterface
    {
        $gd = $image->core()->native();

        if (! ($gd instanceof \GdImage)) {
            throw new \RuntimeException('GD image handle unavailable for background removal.');
        }

        $w = imagesx($gd);
        $h = imagesy($gd);

        if ($w < 2 || $h < 2) {
            throw new \RuntimeException('Image too small for background detection.');
        }

        // Keep alpha through export.
        imagealphablending($gd, false);
        imagesavealpha($gd, true);

        // Establish the presumed background colour from the four corners.
        $corners = [
            imagecolorat($gd, 0, 0),
            imagecolorat($gd, $w - 1, 0),
            imagecolorat($gd, 0, $h - 1),
            imagecolorat($gd, $w - 1, $h - 1),
        ];
        $bg = $this->averageRgb($corners);

        // Reject ambiguous backgrounds where the corners differ noticeably.
        foreach ($corners as $corner) {
            if ($this->distance($this->toRgb($corner), $bg) > 25) {
                throw new \RuntimeException('Background is not a dominant solid colour.');
            }
        }

        $tolerance   = 32; // Euclidean RGB distance still considered "background".
        $transparent = imagecolorallocatealpha($gd, 0, 0, 0, 127);
        $visited     = str_repeat("\0", $w * $h);

        // Seed the flood fill with every border pixel.
        $stack = [];
        for ($x = 0; $x < $w; $x++) {
            $stack[] = [$x, 0];
            $stack[] = [$x, $h - 1];
        }
        for ($y = 0; $y < $h; $y++) {
            $stack[] = [0, $y];
            $stack[] = [$w - 1, $y];
        }

        while (! empty($stack)) {
            [$x, $y] = array_pop($stack);

            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                continue;
            }

            $idx = $y * $w + $x;
            if ($visited[$idx] === "\1") {
                continue;
            }
            $visited[$idx] = "\1";

            if ($this->distance($this->toRgb(imagecolorat($gd, $x, $y)), $bg) > $tolerance) {
                continue;
            }

            imagesetpixel($gd, $x, $y, $transparent);

            $stack[] = [$x + 1, $y];
            $stack[] = [$x - 1, $y];
            $stack[] = [$x, $y + 1];
            $stack[] = [$x, $y - 1];
        }

        return $image;
    }

    /**
     * Split a GD integer colour into [r, g, b].
     *
     * @return array{0:int,1:int,2:int}
     */
    protected function toRgb(int $color): array
    {
        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    /**
     * Average a list of GD integer colours into a single [r, g, b].
     *
     * @param  array<int>  $colors
     * @return array{0:int,1:int,2:int}
     */
    protected function averageRgb(array $colors): array
    {
        $r = $g = $b = 0;
        $n = max(count($colors), 1);

        foreach ($colors as $color) {
            [$cr, $cg, $cb] = $this->toRgb($color);
            $r += $cr;
            $g += $cg;
            $b += $cb;
        }

        return [(int) round($r / $n), (int) round($g / $n), (int) round($b / $n)];
    }

    /**
     * Euclidean distance between two [r, g, b] colours.
     *
     * @param  array{0:int,1:int,2:int}  $a
     * @param  array{0:int,1:int,2:int}  $b
     */
    protected function distance(array $a, array $b): float
    {
        return sqrt(
            ($a[0] - $b[0]) ** 2 +
            ($a[1] - $b[1]) ** 2 +
            ($a[2] - $b[2]) ** 2
        );
    }
}
