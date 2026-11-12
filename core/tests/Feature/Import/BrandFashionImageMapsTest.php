<?php

use App\Domain\Import\BrandFashion\LegacyRedirects;

/*
 * Block D (2026-11-12): when the image pass (bf:legacy-images) made the images, the import REUSES them and writes none to
 * its own image-map.jsonl — so the redirects must read the shard maps beside it, or no old image URL would be mapped.
 */

it('maps the old image URLs from the import map AND the image pass shard maps, one row per original', function (): void {
    $dir = sys_get_temp_dir().'/bf-maps-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/products.jsonl', '');
    file_put_contents($dir.'/site.json', '{}');
    $line = fn (string $source, string $file): string => json_encode(['source' => $source, 'stored' => ['folder' => 'Product', 'file' => $file]], JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($dir.'/image-map.jsonl', $line('2024/01/a.jpg', 'a.webp'));
    file_put_contents($dir.'/image-map.shard-1-of-2.jsonl', $line('2024/01/b.jpg', 'b.webp').$line('2024/01/a.jpg', 'a-again.webp'));
    file_put_contents($dir.'/image-map.shard-2-of-2.jsonl', $line('2025/03/c.jpg', 'c.webp'));

    $images = array_values(array_filter(
        app(LegacyRedirects::class)->build($dir.'/products.jsonl', $dir.'/site.json', $dir.'/image-map.jsonl', 'https://api.example.org/'),
        fn (array $r): bool => $r['kind'] === 'image'));
    $byFrom = array_column($images, 'to', 'from');
    ksort($byFrom);

    expect($byFrom)->toBe([
        '/wp-content/uploads/2024/01/a.jpg' => 'https://api.example.org/Uploads_Images/Product/a.webp',   // the import's own map first
        '/wp-content/uploads/2024/01/b.jpg' => 'https://api.example.org/Uploads_Images/Product/b.webp',
        '/wp-content/uploads/2025/03/c.jpg' => 'https://api.example.org/Uploads_Images/Product/c.webp',
    ])->and(count($images))->toBe(3);

    // only shard maps (the import processed nothing itself, so its own map was never created)
    unlink($dir.'/image-map.jsonl');
    $only = array_filter(app(LegacyRedirects::class)->build($dir.'/products.jsonl', $dir.'/site.json', $dir.'/image-map.jsonl', 'https://api.example.org'),
        fn (array $r): bool => $r['kind'] === 'image');
    expect(count($only))->toBe(3);

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});
