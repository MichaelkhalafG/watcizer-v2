<?php

use App\Domain\Media\ImagePipeline;
use App\Domain\Media\MediaStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * `media:backfill-renditions` (2026-10-22): the ladder for a master that has none, through the same
 * pipeline an upload uses, recorded on the row, never touching the master, restartable.
 *
 * Everything happens in a TEMPORARY media root (`config media.root`) so the shared tree is never
 * written, and the image row rides the suite's transaction. The master is made by the pipeline itself
 * from an 800 px picture — padded onto the 1,200 canvas exactly as a real upload is — and then stripped
 * of its ladder, which is precisely the state the transform left 2,958 rows in.
 */

/**
 * A temporary media root with a Product folder holding one padded master and nothing else.
 *
 * @return array{root: string, master: string, base: string, rowId: int}
 */
function backfillFixture(): array
{
    $root = sys_get_temp_dir().'/wz-backfill-'.uniqid();
    mkdir($root.'/Product', 0775, true);
    config(['media.root' => $root]);

    // An 800 × 800 picture, ink to its edges, so the padded master holds a photograph exactly 800 wide.
    $image = imagecreatetruecolor(800, 800);
    $ink = imagecolorallocate($image, 20, 30, 40);
    $light = imagecolorallocate($image, 200, 120, 60);
    imagefill($image, 0, 0, $ink === false ? 0 : $ink);
    imagefilledrectangle($image, 100, 100, 699, 699, $light === false ? 0 : $light);
    $source = tempnam(sys_get_temp_dir(), 'bf').'.png';
    imagepng($image, $source);
    imagedestroy($image);

    $stored = app(MediaStore::class)->storePath($source, 'product');
    @unlink($source);
    $base = preg_replace('/\.webp$/', '', $stored['file']) ?? $stored['file'];
    foreach (glob($root.'/Product/'.$base.'-*') ?: [] as $rendition) {
        unlink($rendition);            // the transform's state: a master with no ladder
    }

    $productId = T::int(DB::table('catalog_products')->orderBy('id')->value('id'));
    $rowId = (int) DB::table('catalog_product_images')->insertGetId([
        'product_id' => $productId, 'path' => 'Product/'.$stored['file'], 'is_cover' => 0, 'sort' => 90,
        'width' => null, 'height' => null, 'renditions' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['root' => $root, 'master' => $root.'/Product/'.$stored['file'], 'base' => $base, 'rowId' => $rowId];
}

function backfillCleanup(string $root): void
{
    foreach (glob($root.'/Product/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root.'/Product');
    @rmdir($root);
}

it('dry-runs without writing, then writes the ladder beside the master, records it, and leaves the master untouched', function () {
    $f = backfillFixture();
    try {
        $masterBefore = [filesize($f['master']), filemtime($f['master']), md5_file($f['master'])];
        expect(glob($f['root'].'/Product/'.$f['base'].'-*'))->toBe([], 'the fixture starts with no renditions');

        expect(Artisan::call('media:backfill-renditions', ['--dry-run' => true, '--type' => ['product'], '--id' => [$f['rowId']]]))->toBe(0);
        $out = Artisan::output();
        expect($out)->toContain('DRY RUN')
            ->and(glob($f['root'].'/Product/'.$f['base'].'-*'))->toBe([], 'a dry run writes nothing')
            ->and(DB::table('catalog_product_images')->where('id', $f['rowId'])->value('renditions'))->toBeNull('a dry run records nothing');

        expect(Artisan::call('media:backfill-renditions', ['--type' => ['product'], '--id' => [$f['rowId']], '--pause-ms' => 0]))->toBe(0);
        $out = Artisan::output();
        $files = glob($f['root'].'/Product/'.$f['base'].'-*') ?: [];
        sort($files);
        $formats = count(\App\Domain\Media\MediaCapabilities::renditionFormats());
        // An 800 px picture gets 160 / 320 / 480 / 640 and NOT 960 (D-16), in every format this host writes.
        expect(count($files))->toBe(4 * $formats, 'four rungs below the 800 px picture, per format: '.implode(', ', array_map('basename', $files)))
            ->and($out)->toContain('1 recorded');
        foreach ($files as $file) {
            expect(filesize($file))->toBeGreaterThan(0, basename($file).' has bytes');
        }

        clearstatcache();
        expect([filesize($f['master']), filemtime($f['master']), md5_file($f['master'])])->toBe($masterBefore, 'the original is never touched');

        $row = T::row(DB::table('catalog_product_images')->where('id', $f['rowId'])->first());
        $recorded = T::arr(json_decode(T::str($row->renditions), true));
        expect(array_keys($recorded))->toBe([160, 320, 480, 640], 'keyed by width, the shape the pipeline writes')
            ->and(T::arr($recorded[320] ?? null)['webp'] ?? null)->toBe($f['base'].'-320.webp', 'a bare filename beside its master')
            ->and(T::int($row->width))->toBeGreaterThanOrEqual(798)->toBeLessThanOrEqual(802)
            ->and($row->renditions_failed)->toBeNull();
        foreach ($recorded as $perFormat) {
            foreach (T::arr($perFormat) as $file) {
                expect(is_file($f['root'].'/Product/'.T::str($file)))->toBeTrue('every recorded file exists: '.T::str($file));
            }
        }

        // Idempotent: a recorded row is no longer a candidate.
        expect(Artisan::call('media:backfill-renditions', ['--type' => ['product'], '--id' => [$f['rowId']], '--pause-ms' => 0]))->toBe(0);
        expect(Artisan::output())->toContain('processed 0 row(s)');
    } finally {
        backfillCleanup($f['root']);
    }
});

it('resumes: a run finds the files an earlier, killed run wrote and encodes only what is missing', function () {
    $f = backfillFixture();
    try {
        expect(Artisan::call('media:backfill-renditions', ['--type' => ['product'], '--id' => [$f['rowId']], '--pause-ms' => 0]))->toBe(0);
        $files = glob($f['root'].'/Product/'.$f['base'].'-*') ?: [];
        $count = count($files);
        expect($count)->toBeGreaterThan(0);

        // "Killed halfway": one rendition gone, the row never recorded.
        $victim = $f['root'].'/Product/'.$f['base'].'-480.webp';
        expect(is_file($victim))->toBeTrue();
        unlink($victim);
        DB::table('catalog_product_images')->where('id', $f['rowId'])->update(['renditions' => null]);
        // Older than the run that follows, so its "written this run" window cannot mistake them for new files.
        $kept = [];
        foreach ($files as $file) {
            if ($file !== $victim) {
                touch($file, time() - 60);
                $kept[$file] = time() - 60;
            }
        }
        clearstatcache();
        sleep(1);

        expect(Artisan::call('media:backfill-renditions', ['--type' => ['product'], '--id' => [$f['rowId']], '--pause-ms' => 0]))->toBe(0);
        $out = Artisan::output();
        expect($out)->toContain('1 rendition file(s) written')
            ->and($out)->toContain(($count - 1).' already on disk and reused')
            ->and(is_file($victim))->toBeTrue('the missing rung is back')
            ->and(count(glob($f['root'].'/Product/'.$f['base'].'-*') ?: []))->toBe($count);
        clearstatcache();
        foreach ($kept as $file => $mtime) {
            expect(filemtime($file))->toBe($mtime, basename($file).' was reused, not re-encoded');
        }
        expect(T::str(DB::table('catalog_product_images')->where('id', $f['rowId'])->value('renditions')))->toContain('-480.webp');
    } finally {
        backfillCleanup($f['root']);
    }
});

it('skips a row whose master is not on disk and records nothing for it', function () {
    $root = sys_get_temp_dir().'/wz-backfill-'.uniqid();
    mkdir($root.'/Product', 0775, true);
    config(['media.root' => $root]);
    $productId = T::int(DB::table('catalog_products')->orderBy('id')->value('id'));
    $rowId = (int) DB::table('catalog_product_images')->insertGetId([
        'product_id' => $productId, 'path' => 'Product/never-there-'.uniqid().'.webp', 'is_cover' => 0, 'sort' => 91,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    try {
        expect(Artisan::call('media:backfill-renditions', ['--type' => ['product'], '--id' => [$rowId], '--pause-ms' => 0]))->toBe(0);
        expect(Artisan::output())->toContain('1 master(s) missing')
            ->and(DB::table('catalog_product_images')->where('id', $rowId)->value('renditions'))->toBeNull()
            ->and(DB::table('catalog_product_images')->where('id', $rowId)->value('renditions_failed'))->toBeNull()
            ->and(glob($root.'/Product/*'))->toBe([]);
    } finally {
        backfillCleanup($root);
    }
});

it('measures the photograph inside a padded canvas, not the canvas', function () {
    $canvas = imagecreatetruecolor(1200, 1200);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    $ink = imagecolorallocate($canvas, 10, 10, 10);
    imagefill($canvas, 0, 0, $white === false ? 0 : $white);
    imagefilledrectangle($canvas, 200, 300, 999, 899, $ink === false ? 0 : $ink);   // 800 wide, 600 tall

    [$w, $h] = ImagePipeline::pictureSize($canvas);
    imagedestroy($canvas);

    expect($w)->toBe(800)->and($h)->toBeGreaterThanOrEqual(600)->toBeLessThanOrEqual(604);

    $blank = imagecreatetruecolor(300, 200);
    $white = imagecolorallocate($blank, 255, 255, 255);
    imagefill($blank, 0, 0, $white === false ? 0 : $white);
    expect(ImagePipeline::pictureSize($blank))->toBe([300, 200], 'a blank canvas is reported whole');
    imagedestroy($blank);
});
