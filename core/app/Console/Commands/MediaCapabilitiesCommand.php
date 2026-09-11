<?php

namespace App\Console\Commands;

use App\Domain\Media\MediaCapabilities;
use App\Domain\Media\MediaStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * media:capabilities — what THIS host can do with images, and whether it can write the tree.
 *
 * Run it on the shared host before wave 4B starts uploading: the renditions §5.4 specifies include
 * AVIF, and AVIF depends on how PHP's GD was built. If this prints `write_avif: no`, the pipeline
 * still works (WebP only) but the frontend loader must not advertise AVIF — better a line in a
 * deployment check than a 404 per image later.
 */
final class MediaCapabilitiesCommand extends Command
{
    protected $signature = 'media:capabilities';

    protected $description = 'Report GD image capabilities and whether the shared Uploads_Images tree is writable';

    public function handle(): int
    {
        $report = MediaCapabilities::report();
        $rows = [];
        foreach ($report as $key => $value) {
            $rows[] = [$key, is_bool($value) ? ($value ? 'yes' : 'no') : $value];
        }
        $this->table(['capability', 'value'], $rows);

        $formats = MediaCapabilities::renditionFormats();
        $this->line('rendition formats on this host: '.($formats === [] ? 'NONE' : implode(', ', $formats)));

        if (! MediaCapabilities::canWriteWebp()) {
            $this->error('GD cannot write WebP here, and WebP is the master format — uploads would fail.');

            return self::FAILURE;
        }
        if (! MediaCapabilities::canWriteAvif()) {
            $this->warn('GD cannot write AVIF here: renditions will be WebP only (study §5.4 expects both).');
        }

        $this->newLine();
        $rows = [];
        foreach (MediaStore::types() as $type) {
            $folder = MediaStore::typeConfig($type)['folder'];
            try {
                $directory = MediaStore::directory($folder);
                $writable = is_writable($directory);
                $rows[] = [$type, $folder, $directory, $writable ? 'writable' : 'NOT WRITABLE'];
            } catch (Throwable $e) {
                $rows[] = [$type, $folder, '—', 'FAILED: '.$e->getMessage()];
            }
        }
        $this->table(['type', 'folder', 'path', 'state'], $rows);

        $bad = array_filter($rows, fn (array $row): bool => $row[3] !== 'writable');
        if ($bad !== []) {
            $this->error(count($bad).' media folder(s) are not writable — check the shared Uploads_Images mount (§1).');

            return self::FAILURE;
        }

        $this->info('Media pipeline is ready on this host.');

        return self::SUCCESS;
    }
}
