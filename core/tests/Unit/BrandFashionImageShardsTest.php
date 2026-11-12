<?php

use App\Domain\Import\BrandFashion\LegacyImagePass;

/*
 * Block D (2026-11-12): the image pass's shards are disjoint and together are every file — so `--shard=1/4` … `4/4` run
 * in parallel never touch the same original, and miss none.
 */

it('puts every file in exactly one shard, for any number of shards', function () {
    $files = [];
    for ($i = 0; $i < 2000; $i++) {
        $files[] = sprintf('20%02d/%02d/photo-%d.jpg', 23 + $i % 4, 1 + $i % 12, $i);
    }
    foreach ([1, 2, 3, 4, 6, 8] as $n) {
        $seen = [];
        for ($k = 1; $k <= $n; $k++) {
            foreach ($files as $f) {
                if (LegacyImagePass::inShard($f, $k, $n)) {
                    $seen[$f] = ($seen[$f] ?? 0) + 1;
                }
            }
        }
        expect(count($seen))->toBe(count($files))
            ->and(array_unique(array_values($seen)))->toBe([1]);
    }
});

it('reads a shard as K/N and refuses anything else', function () {
    expect(LegacyImagePass::parseShard('2/4'))->toBe([2, 4])
        ->and(LegacyImagePass::parseShard('1/1'))->toBe([1, 1])
        ->and(LegacyImagePass::parseShard('5/4'))->toBeNull()
        ->and(LegacyImagePass::parseShard('0/4'))->toBeNull()
        ->and(LegacyImagePass::parseShard('2'))->toBeNull()
        ->and(LegacyImagePass::mapFile('/x/out/', 2, 4))->toBe('/x/out/image-map.shard-2-of-4.jsonl');
});
