<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * media:verify — does every image the database names actually exist, and did a tree copy land whole?
 *
 * ── Why this is not "assert every referenced file exists" ────────────────────────────────────
 *
 * The obvious check fails on this machine for a reason that has nothing to do with copying: the
 * workstation's `Uploads_Images` is a PARTIAL copy of production (recorded in AGENTS since wave 1).
 * 65 brand rows name an image and the local `Brand/` folder holds 3 files. A verifier that called
 * that a failure would cry wolf on the developer's laptop every time, and the one run where it
 * mattered would look identical to the sixty that did not.
 *
 * So it answers two questions separately:
 *
 *   1. **Is the copy FAITHFUL?** (`--against=<old tree>`) Every file in the old tree exists in the
 *      new one, same size. This is the question a move actually asks, and it is decidable even
 *      where the source was already incomplete.
 *   2. **What does the database name that is not on disk?** Reported as a BASELINE, not a failure —
 *      and after a copy it must be the SAME baseline as before, which is the real proof that the
 *      copy did not silently drop the rows that matter.
 *
 * Exit 1 only when the copy is unfaithful, or when a referenced file that existed before is gone.
 */
final class MediaVerifyCommand extends Command
{
    protected $signature = 'media:verify
        {--against= : Path of the OLD tree, to prove a copy landed whole}
        {--show=15 : How many example missing files to print}';

    protected $description = 'Verify the image tree: every DB-referenced file, and (optionally) that a copy is faithful';

    /**
     * Legacy tables that name a bare filename, and the folder each implies.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LEGACY = [
        'products' => ['image', 'Product'],
        'product_images' => ['image', 'Product_image'],
        'brands' => ['image', 'Brand'],
        'categories' => ['image', 'Category'],
        'sub_types' => ['image', 'Sub_type'],
        'category_types' => ['image', 'Category_type'],
        'grades' => ['image', 'Grade'],
        'banner_homes' => ['image', 'Banner_home'],
        'banner_sides' => ['image', 'Banner_Side'],
        'banner_bottoms' => ['image', 'Banner_Bottom'],
        'offers' => ['image', 'Offer'],
        'blogs' => ['image', 'Blog'],
        'users' => ['image', 'User'],
    ];

    public function handle(): int
    {
        $root = $this->treeRoot();
        $this->info('media:verify');
        $this->line("  tree    {$root}");

        if (! is_dir($root)) {
            $this->error("  the configured tree does not exist: {$root}");

            return self::FAILURE;
        }

        // ── 1. what the database names ───────────────────────────────────────────────────────
        $referenced = $this->referenced();
        $missing = [];
        foreach (array_keys($referenced) as $relative) {
            if (! is_file($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $missing[] = $relative;
            }
        }

        $present = count($referenced) - count($missing);
        $this->line(sprintf('  db      %s referenced, %s present, %s absent',
            number_format(count($referenced)), number_format($present), number_format(count($missing))));

        // ── 2. what is on disk ───────────────────────────────────────────────────────────────
        $onDisk = $this->filesUnder($root);
        $this->line(sprintf('  disk    %s files, %s unreferenced (the download cache — see §5.4)',
            number_format(count($onDisk)), number_format(max(0, count($onDisk) - $present))));

        if ($missing !== []) {
            $show = max(0, (int) $this->option('show'));
            $this->line('');
            $this->warn('  Referenced but NOT on disk — a BASELINE, not necessarily a fault:');
            $this->line('  (this workstation holds a partial copy of production; see AGENTS)');
            foreach (array_slice($missing, 0, $show) as $file) {
                $this->line('    '.$file);
            }
            if (count($missing) > $show) {
                $this->line('    … and '.number_format(count($missing) - $show).' more');
            }
        }

        // ── 3. was a copy faithful? ──────────────────────────────────────────────────────────
        $against = $this->option('against');
        if (! is_string($against) || $against === '') {
            $this->line('');
            $this->info('  Pass --against=<old tree> after a copy to prove it landed whole.');

            return self::SUCCESS;
        }

        return $this->compare($against, $root, $onDisk);
    }

    /**
     * Every file the old tree has, present in the new one at the same size.
     *
     * Size rather than a hash: 484 MB of hashing to catch a failure mode (a byte flipped inside an
     * otherwise complete file) that a local file copy does not have. A truncated or missing file —
     * which is what a copy actually gets wrong — changes the size.
     *
     * @param  array<string, int>  $newFiles  relative path => size
     */
    private function compare(string $oldRoot, string $newRoot, array $newFiles): int
    {
        if (! is_dir($oldRoot)) {
            $this->error("  --against tree does not exist: {$oldRoot}");

            return self::FAILURE;
        }

        $oldFiles = $this->filesUnder($oldRoot);

        $absent = [];
        $wrongSize = [];
        foreach ($oldFiles as $relative => $size) {
            if (! array_key_exists($relative, $newFiles)) {
                $absent[] = $relative;
            } elseif ($newFiles[$relative] !== $size) {
                $wrongSize[] = $relative;
            }
        }

        $this->line('');
        $this->info('  copy check');
        $this->line(sprintf('    old tree  %s files', number_format(count($oldFiles))));
        $this->line(sprintf('    new tree  %s files', number_format(count($newFiles))));
        $this->line(sprintf('    missing   %s', number_format(count($absent))));
        $this->line(sprintf('    truncated %s', number_format(count($wrongSize))));

        $show = max(0, (int) $this->option('show'));
        foreach ([['missing', $absent], ['truncated', $wrongSize]] as [$label, $list]) {
            foreach (array_slice($list, 0, $show) as $file) {
                $this->line("    {$label}: {$file}");
            }
        }

        if ($absent !== [] || $wrongSize !== []) {
            $this->error('  COPY IS NOT FAITHFUL — do not delete the old tree.');

            return self::FAILURE;
        }

        // Extra files in the new tree are fine: uploads made since the copy.
        $this->info('  COPY IS FAITHFUL — every file in the old tree is present at the same size.');

        return self::SUCCESS;
    }

    /**
     * Relative path => size, for every file under a tree.
     *
     * @return array<string, int>
     */
    private function filesUnder(string $root): array
    {
        $out = [];
        $rootLength = strlen(rtrim($root, '\\/')) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), $rootLength));
            $out[$relative] = $file->getSize();
        }

        return $out;
    }

    /**
     * Every file the database names, as `Folder/file`.
     *
     * @return array<string, true>
     */
    private function referenced(): array
    {
        $refs = [];

        foreach (DB::table('catalog_product_images')->pluck('path') as $path) {
            if (is_string($path) && $path !== '') {
                $refs[$path] = true;
            }
        }

        foreach (self::LEGACY as $table => [$column, $folder]) {
            try {
                foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $file) {
                    if (is_string($file) && $file !== '') {
                        $refs[$folder.'/'.$file] = true;
                    }
                }
            } catch (\Throwable) {
                // A table this database does not have is not a fault: the set is deliberately wide.
                continue;
            }
        }

        return $refs;
    }

    private function treeRoot(): string
    {
        $root = config()->string('media.root');

        return str_starts_with($root, '..') || ! preg_match('#^([A-Za-z]:|/)#', $root)
            ? base_path($root)
            : $root;
    }
}
