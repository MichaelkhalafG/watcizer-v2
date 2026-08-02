<?php

namespace App\Console\Commands;

use App\Services\ImageService;
use Illuminate\Console\Command;

/**
 * Issue 1C — bring already-uploaded product images up to the pad-to-square
 * standard, in place (same paths → no DB changes).
 *
 * Walks Uploads_Images/Product (main images) and Uploads_Images/Product_image
 * (gallery), re-pads each onto a 1200x1200 white square, and overwrites it.
 * Idempotent: a file already EXACTLY 1200x1200 is skipped (our pipeline always
 * emits 1200x1200, so that is a precise "already processed" marker — a square
 * file smaller than 1200 or with a non-white background is NOT falsely skipped
 * because it will not be exactly 1200x1200).
 */
class ReprocessProductImages extends Command
{
    protected $signature = 'images:reprocess-products {--dry-run : Report what would change without writing any files}';

    protected $description = 'Pad existing product + gallery images onto a 1200x1200 white square, in place (skips files already exactly 1200x1200).';

    public function handle(ImageService $service): int
    {
        $dry     = (bool) $this->option('dry-run');
        $folders = ['Product', 'Product_image'];

        $files = [];
        foreach ($folders as $folder) {
            $dir = public_path('Uploads_Images/' . $folder);
            if (! is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '/*') as $path) {
                if (is_file($path) && preg_match('/\.(webp|jpe?g|png|gif)$/i', $path)) {
                    $files[] = $path;
                }
            }
        }

        $total = count($files);
        if ($total === 0) {
            $this->warn('No product images found under Uploads_Images/Product or /Product_image.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "Scanning {$total} product image(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;
        $lines     = [];   // buffered so per-file output doesn't garble the bar

        foreach ($files as $path) {
            $rel = ltrim(str_replace(public_path(), '', $path), '\\/');
            try {
                $r = $service->padExistingToSquare($path, 1200, 85, $dry);
                if ($r['skipped']) {
                    $skipped++;
                    $lines[] = sprintf('SKIP       %s  (%dx%d already square)', $rel, $r['old_w'], $r['old_h']);
                } else {
                    $processed++;
                    $lines[] = sprintf(
                        '%s  %s  (%dx%d → %dx%d)',
                        $dry ? 'WOULD-PAD' : 'PADDED   ',
                        $rel,
                        $r['old_w'], $r['old_h'], $r['new_w'], $r['new_h']
                    );
                }
            } catch (\Throwable $e) {
                $failed++;
                $lines[] = sprintf('FAILED     %s  (%s)', $rel, $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        foreach ($lines as $line) {
            $this->line($line);
        }
        $this->newLine();
        $this->info(sprintf(
            '%sDone — %d processed, %d skipped, %d failed, %d total.',
            $dry ? '[DRY RUN] ' : '',
            $processed,
            $skipped,
            $failed,
            $total
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
