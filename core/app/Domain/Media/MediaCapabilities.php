<?php

namespace App\Domain\Media;

/**
 * What this host's GD can actually encode — asked at runtime, never assumed.
 *
 * The renditions §5.4 asks for include AVIF, and AVIF support in GD depends on how PHP was built:
 * it is present on this workstation (PHP 8.3 + libavif) and it may not be on the shared Hostinger
 * host. The pipeline therefore DEGRADES rather than fails — WebP always, AVIF when possible — and
 * says which, so "no AVIF in production" is a line in a report instead of a mystery about missing
 * files months later.
 *
 * `media:capabilities` prints this for whichever host it is run on.
 */
final class MediaCapabilities
{
    public static function hasGd(): bool
    {
        return function_exists('imagecreatetruecolor');
    }

    public static function canWriteWebp(): bool
    {
        return function_exists('imagewebp');
    }

    public static function canWriteAvif(): bool
    {
        return function_exists('imageavif');
    }

    public static function canReadWebp(): bool
    {
        return function_exists('imagecreatefromwebp');
    }

    public static function canReadAvif(): bool
    {
        return function_exists('imagecreatefromavif');
    }

    /** @return array<string, bool|string> */
    public static function report(): array
    {
        $gd = self::hasGd() && function_exists('gd_info') ? gd_info() : [];
        $version = $gd['GD Version'] ?? null;

        return [
            'gd' => self::hasGd(),
            'gd_version' => is_string($version) ? $version : 'n/a',
            'read_jpeg' => function_exists('imagecreatefromjpeg'),
            'read_png' => function_exists('imagecreatefrompng'),
            'read_webp' => self::canReadWebp(),
            'read_avif' => self::canReadAvif(),
            'write_webp' => self::canWriteWebp(),
            'write_avif' => self::canWriteAvif(),
        ];
    }

    /**
     * The formats renditions will actually be written in on this host.
     *
     * @return list<string>
     */
    public static function renditionFormats(): array
    {
        $formats = [];
        if (self::canWriteAvif()) {
            $formats[] = 'avif';
        }
        if (self::canWriteWebp()) {
            $formats[] = 'webp';
        }

        return $formats;
    }
}
