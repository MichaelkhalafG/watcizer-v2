<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\LegacyImagePass;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;

/**
 * `php artisan bf:legacy-images <extract> --images=<root> --out=<import out> [--shard=K/N] [--apply] [--bench=50]`
 * (Block D, 2026-11-12) — the image pass of the legacy import, in parallel shards ({@see LegacyImagePass}).
 *
 * Run AFTER bf:catalogue-clean / prepare (it computes the same plan as the import, on the same database) and BEFORE
 * bf:legacy-import with the SAME `--out`: the import then reuses every image the shards made. Each shard is its own
 * process (`nohup … &`), re-runnable; together they are all the files. It writes media files only — never the database.
 * A dry run (default) says how many files the import needs and how many fall in this shard.
 */
final class BrandFashionLegacyImagesCommand extends Command
{
    protected $signature = 'bf:legacy-images
        {extract : the bf:legacy-extract folder (products.jsonl)}
        {--images= : the folder holding the extracted originals (2023/ 2024/ …)}
        {--out= : the import\'s output folder (the same --out bf:legacy-import is given)}
        {--shard=1/1 : this process\'s share, K/N}
        {--apply : process (default: say what would be processed)}
        {--bench=0 : instead: time this many originals into a throwaway folder, and stop}
        {--allow-remote : required on a non-local database (the plan reads it)}';

    protected $description = 'Brand Fashion: process the legacy originals in parallel shards before the import (dry run by default)';

    public function handle(LegacyImagePass $pass): int
    {
        $dir = rtrim(str_replace('\\', '/', Coerce::str($this->argument('extract'))), '/');
        $images = rtrim(str_replace('\\', '/', Coerce::str($this->option('images'))), '/');
        if (! is_file($dir.'/products.jsonl') || $images === '' || ! is_dir($images)) {
            $this->error('Give the extract folder (with products.jsonl) and --images=<the originals folder>.');

            return self::FAILURE;
        }

        $bench = Coerce::int($this->option('bench'));
        if ($bench > 0) {
            $files = [];
            foreach (file($dir.'/images-needed.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $f) {
                $f = ltrim($f, "\u{FEFF}");
                if (is_file($images.'/'.$f)) {
                    $files[] = $f;
                }
                if (count($files) >= $bench) {
                    break;
                }
            }
            $r = $pass->bench($files, $images, $bench);
            $this->info(sprintf('bench: %d originals — mean %.0f ms, median %.0f ms, max %.0f ms per image; peak %.1f MB (one process)', $r['images'], $r['mean_ms'], $r['median_ms'], $r['max_ms'], $r['peak_mb']));

            return self::SUCCESS;
        }

        $shard = LegacyImagePass::parseShard(Coerce::str($this->option('shard'), '1/1'));
        if ($shard === null) {
            $this->error('--shard must be K/N with 1 <= K <= N.');

            return self::FAILURE;
        }
        [$k, $n] = $shard;
        $out = rtrim(str_replace('\\', '/', Coerce::str($this->option('out'))), '/');
        if ($out === '' || (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out))) {
            $this->error('Give --out=<the import\'s output folder>.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if (! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'bf:legacy-images', $remote, ['reads the import plan from this database; writes media files'], ['run it on a local copy', 'or pass --allow-remote']);

                return self::FAILURE;
            }
        }

        $started = microtime(true);
        $files = $pass->files($dir.'/products.jsonl', $images);
        $mine = count(array_filter($files, fn (string $f): bool => LegacyImagePass::inShard($f, $k, $n)));
        $this->info(sprintf('bf:legacy-images — shard %d/%d — %s', $k, $n, $apply ? 'APPLY' : 'DRY RUN (nothing is written)'));
        $this->line(sprintf('  the import will store %d originals; %d fall in this shard (planned in %.1f s)', count($files), $mine, microtime(true) - $started));
        if (! $apply) {
            return self::SUCCESS;
        }
        $r = $pass->run($files, $images, $out, $k, $n, function (int $done, int $of): void {
            $this->line(sprintf('  %d / %d at %s', $done, $of, date('H:i:s')));
        });
        $this->info(sprintf('  shard %d/%d: %d processed, %d already done, %d unreadable — %.0f s; map %s', $k, $n, $r['processed'], $r['already'], $r['unreadable'], $r['ms'] / 1000, LegacyImagePass::mapFile($out, $k, $n)));

        return self::SUCCESS;
    }
}
