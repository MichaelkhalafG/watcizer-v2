<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Assign images to sub_types that have none (test/dummy data).
 *
 * Images live in per-entity subfolders under public/Uploads_Images. We build a
 * pool of {folder-prefixed} filenames (e.g. "Sub_type/bags.webp",
 * "Product/xxx.webp") and store the value WITH its subfolder prefix so the
 * frontend getImageUrl(value) resolves it directly (no folder arg needed).
 * Name-matching against the Sub_type folder is tried first, then a random pick.
 */
class SubTypeImageSeeder extends Seeder
{
    public function run(): void
    {
        $scan = function (string $sub) {
            $dir = public_path('Uploads_Images/' . $sub);
            if (!is_dir($dir)) {
                return [];
            }
            $ext = ['jpg', 'jpeg', 'png', 'webp'];
            return collect(scandir($dir))
                ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $ext))
                ->values()
                ->toArray();
        };

        $subTypeFiles = $scan('Sub_type');                 // bare filenames in Sub_type/
        $subTypePool = array_map(fn ($f) => 'Sub_type/' . $f, $subTypeFiles);
        $productPool = array_map(fn ($f) => 'Product/' . $f, $scan('Product'));

        // Prefer Sub_type images; add Product images for variety (only 3 Sub_type files exist).
        $pool = array_values(array_merge($subTypePool, $productPool));

        if (empty($pool)) {
            $this->command->error('No images found in Uploads_Images/Sub_type or /Product!');
            return;
        }
        $this->command->info('Images available: ' . count($pool)
            . ' (Sub_type: ' . count($subTypePool) . ', Product: ' . count($productPool) . ')');

        $missing = DB::table('sub_types')
            ->where(function ($q) {
                $q->whereNull('image')->orWhere('image', '');
            })
            ->pluck('id');

        $this->command->info('Sub-types missing images: ' . count($missing));

        DB::transaction(function () use ($missing, $pool, $subTypeFiles) {
            foreach ($missing as $id) {
                $name = DB::table('sub_type_translations')
                    ->where('sub_type_id', $id)
                    ->where('locale', 'en')
                    ->value('sub_type_name');

                // Try to match a Sub_type file by name (e.g. "Bags" → bags.webp).
                $matched = null;
                if ($name) {
                    $needle = strtolower($name);
                    foreach ($subTypeFiles as $f) {
                        if (str_contains(strtolower($f), $needle)) {
                            $matched = 'Sub_type/' . $f;
                            break;
                        }
                    }
                }

                $value = $matched ?? $pool[array_rand($pool)];

                DB::table('sub_types')->where('id', $id)->update(['image' => $value]);
                $this->command->info("Sub-type {$id} ({$name}): {$value}");
            }
        });

        $this->command->info('✅ SubType images assigned.');
    }
}
