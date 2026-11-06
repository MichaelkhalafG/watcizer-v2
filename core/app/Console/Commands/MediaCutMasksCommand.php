<?php

namespace App\Console\Commands;

use App\Domain\Media\CutoutMask;
use App\Storefront\CutMasks;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * media:cut-masks — the background mask of every COVER photo that has not been examined yet, made ahead of
 * time (the developer's review, 2026-11-05, item 32; {@see CutoutMask}).
 *
 * Nothing depends on this command: a storefront makes a missing mask itself the first time a blended slider
 * shows that photo ({@see CutMasks::forProducts()}). It exists so a whole catalogue can be made ready at a
 * quiet hour, and to make the masks again after the rule changes (`--again`).
 *
 *  • `--dry-run` writes nothing and says how many covers would be examined and how many of their files are
 *    on this disk.
 *  • Restartable: a photo that has been examined (`cut_mask_at`) is not a candidate again.
 *  • Gentle: `--limit` photos per run (default 300), newest products first, `--pause-ms` between them.
 *  • It never writes an image file: the photo is read, and only its row's two columns are written.
 *
 *   php artisan media:cut-masks --dry-run
 *   php artisan media:cut-masks --limit=500
 *   php artisan media:cut-masks --again --limit=500      (examine again the ones already examined)
 */
final class MediaCutMasksCommand extends Command
{
    protected $signature = 'media:cut-masks
        {--dry-run : count what would be examined; write nothing}
        {--limit=300 : photos per run}
        {--pause-ms=20 : pause between photos}
        {--again : also the photos already examined}';

    protected $description = 'Make the background masks of product cover photos (the blend that never tints the product).';

    public function handle(): int
    {
        if (! Schema::hasColumn('catalog_product_images', 'cut_mask')) {
            $this->error('catalog_product_images.cut_mask is not there: run the migration 2026_11_05_000006_image_cut_mask first.');

            return self::FAILURE;
        }

        $limit = max(1, Coerce::int($this->option('limit'), 300));
        $pause = max(0, Coerce::int($this->option('pause-ms'), 20));
        $again = (bool) $this->option('again');

        $candidates = fn (): Builder => DB::table('catalog_product_images as i')
            ->join('catalog_products as p', 'p.id', '=', 'i.product_id')
            ->where('i.is_cover', 1)->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->when(! $again, fn (Builder $q) => $q->whereNull('i.cut_mask_at'));

        $total = $candidates()->count();
        $this->line(sprintf('Cover photos of active products %s: %d.', $again ? '(all, examined or not)' : 'not examined yet', $total));

        $rows = $candidates()->orderByDesc('p.created_at')->orderByDesc('i.id')->limit($limit)->get(['i.id', 'i.path']);

        if ($this->option('dry-run')) {
            $onDisk = 0;
            foreach ($rows as $row) {
                $onDisk += CutMasks::file(Row::str($row, 'path')) === null ? 0 : 1;
            }
            $this->line(sprintf('This run would examine %d of them; %d of those files are on this disk. Nothing was written.', count($rows), $onDisk));

            return self::SUCCESS;
        }

        $made = 0;
        $none = 0;
        $missing = 0;
        $bytes = 0;
        foreach ($rows as $row) {
            $path = Row::str($row, 'path');
            if (CutMasks::file($path) === null) {
                $missing++;

                continue;
            }
            $mask = CutMasks::make(Row::int($row, 'id'), $path);
            if ($mask === null) {
                $none++;
            } else {
                $made++;
                $bytes += strlen($mask);
            }
            if ($pause > 0) {
                usleep($pause * 1000);
            }
        }

        $this->info(sprintf(
            'Examined %d: %d masks made (%s KB in the table, %s KB each on average), %d photos with no studio background, %d files not on this disk (left for a later run).',
            $made + $none, $made, number_format($bytes / 1024, 1), $made > 0 ? number_format($bytes / 1024 / $made, 2) : '0', $none, $missing,
        ));
        if ($total > count($rows)) {
            $this->line(sprintf('%d more to go: run it again.', $total - count($rows)));
        }

        return self::SUCCESS;
    }
}
