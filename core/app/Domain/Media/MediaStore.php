<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where an uploaded image goes, and what comes back (CLEAN_CORE_STUDY §5.4).
 *
 * The single door for writing the shared `Uploads_Images` tree — the `InventoryService` pattern
 * again. Nothing else in the dashboard may compute an image path, because three separate facts
 * have to stay true together and each one is a bug the day it drifts:
 *
 *  1. **The folder is the LEGACY folder.** Both applications write this tree during the
 *     transition, and the compat layer builds URLs as
 *     `<asset_base>/Uploads_Images/<folder>/<file>` (`LegacyJson::image()`). A new folder name
 *     would break every stored row and every cached payload.
 *  2. **The database stores a FILENAME, never a path or a host** (§5.4), so the tree can move to
 *     object storage as a config change.
 *  3. **The master file is the legacy preset**, so a product image uploaded in the new dashboard
 *     and one uploaded in the Blade dashboard are the same kind of file.
 *
 * The filename follows the legacy scheme (`<unix>_<Y-m-d>_<uniqid>.webp`) for the same reason —
 * `ImageService::filename()` produces exactly this, and the transition should not be able to tell
 * which application wrote a file.
 */
final class MediaStore
{
    public function __construct(private readonly ImagePipeline $pipeline) {}

    /**
     * Store one upload of a declared type and return what the database should record.
     *
     * @return array{file: string, folder: string, url: string, width: int, height: int, bytes: int, renditions: array<int, array<string, string>>, skipped: list<string>}
     */
    public function store(UploadedFile $file, string $type): array
    {
        $config = self::typeConfig($type);
        $folder = $config['folder'];
        $directory = self::directory($folder);

        $name = self::filename();
        $absolute = $directory.'/'.$name;

        $result = $this->pipeline->write($file->getRealPath() ?: $file->getPathname(), $absolute, $config);

        return [
            'file' => $name,
            'folder' => $folder,
            'url' => self::url($folder, $name),
            'width' => $result['width'],
            'height' => $result['height'],
            'bytes' => $result['bytes'],
            'renditions' => $result['renditions'],
            'skipped' => $result['skipped'],
        ];
    }

    /** Absolute directory for a legacy folder, created on demand. */
    public static function directory(string $folder): string
    {
        $root = config()->string('media.root');
        $root = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:/', $root) === 1
            ? $root
            : base_path($root);
        $path = rtrim($root, '/\\').'/'.trim($folder, '/\\');

        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Cannot create the media directory [{$path}]. Is the shared Uploads_Images tree mounted?");
        }

        return $path;
    }

    /** Public URL of a stored file — the ONLY place the dashboard turns a filename into a URL. */
    public static function url(string $folder, string $file): string
    {
        return rtrim(config()->string('media.url_base'), '/').'/'.trim($folder, '/').'/'.$file;
    }

    /** @return array{folder: string, master: array{width: int, height: int, quality: int, pad_square: bool}, widths: list<int>, thumbnails: list<int>} */
    public static function typeConfig(string $type): array
    {
        /** @var array<string, mixed> $types */
        $types = config()->array('media.types');
        $config = $types[$type] ?? null;
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown media type [{$type}]. Declare it in config/media.php.");
        }

        /** @var array{folder: string, master: array{width: int, height: int, quality: int, pad_square: bool}, widths: list<int>, thumbnails: list<int>} $config */
        return $config;
    }

    /**
     * Extensions the upload endpoint accepts, from config, narrowed for the validator.
     *
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        $out = [];
        foreach (config()->array('media.upload.mimes') as $mime) {
            if (is_string($mime)) {
                $out[] = $mime;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function types(): array
    {
        $out = [];
        foreach (array_keys(config()->array('media.types')) as $type) {
            $out[] = (string) $type;
        }

        return $out;
    }

    /** The legacy naming scheme, unchanged: `ImageService::filename()`. */
    private static function filename(): string
    {
        return time().'_'.date('Y-m-d').'_'.uniqid().'.webp';
    }
}
