<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RandomImageSeeder extends Seeder
{
    /**
     * Randomly assign existing local images (dummy/test data) to products and
     * brands. Images live in per-entity subfolders under public/Uploads_Images
     * (Product/, Brand/, ...), and the top level holds only folders — so we scan
     * the relevant subfolder per entity and store the bare filename. The frontend
     * helper getImageUrl(value, 'Product'|'Brand') rebuilds the full URL.
     */
    public function run(): void
    {
        $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $scan = function (string $sub) use ($extensions) {
            $dir = public_path('Uploads_Images/' . $sub);
            if (!is_dir($dir)) {
                return [];
            }
            return collect(scandir($dir))
                ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $extensions))
                ->values()
                ->all();
        };

        $productFiles = $scan('Product');
        $brandFiles   = $scan('Brand');

        if (empty($productFiles) && empty($brandFiles)) {
            $this->command->error('No images found in Uploads_Images/Product or Uploads_Images/Brand!');
            return;
        }

        $this->command->info('Found ' . count($productFiles) . ' product images, ' . count($brandFiles) . ' brand images.');

        DB::transaction(function () use ($productFiles, $brandFiles) {
            // ── Assign to PRODUCTS ──
            if (!empty($productFiles)) {
                $products = DB::table('products')->pluck('id');
                foreach ($products as $id) {
                    DB::table('products')
                        ->where('id', $id)
                        ->update(['image' => $productFiles[array_rand($productFiles)]]);
                }
                $this->command->info('Products updated: ' . $products->count());
            } else {
                $this->command->warn('No product images found — products left unchanged.');
            }

            // ── Assign to BRANDS ──
            if (!empty($brandFiles)) {
                $brands = DB::table('brands')->pluck('id');
                foreach ($brands as $id) {
                    DB::table('brands')
                        ->where('id', $id)
                        ->update(['image' => $brandFiles[array_rand($brandFiles)]]);
                }
                $this->command->info('Brands updated: ' . $brands->count());
            } else {
                $this->command->warn('No brand images found — brands left unchanged.');
            }
        });

        $this->command->info('Random image assignment complete.');
    }
}
