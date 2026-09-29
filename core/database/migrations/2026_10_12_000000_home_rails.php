<?php

use App\Domain\Content\HomeRails;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1y (C-1 stage 4, slice C, 2026-09-29): the home page's product rails, one row each, in order.
 *
 * Until now the home page derived its rails in the BROWSER from the whole catalogue: an offers rail,
 * a featured block, then one rail per grade in the grades table's order. Nobody could reorder or
 * switch one off. This table is that list, authored from the dashboard (`HomeRailController`) and
 * read by `catalog/home`.
 *
 *  - `kind` — `offers`, `featured`, `newest` (no target), or `grade`, `brand`, `category_type`
 *    (`target_id` required). {@see HomeRails::KINDS}.
 *  - `target_id` — `catalog_grades.id`, `catalog_brands.id` or `storefront_categories.id` (a
 *    depth-1 node). Deliberately NO foreign key: those are transform-output tables, and a key into
 *    them would stop `core:drop-clean` on switch night. A target that no longer exists yields an
 *    empty rail, and an empty rail is not rendered.
 *  - `title_en` / `title_ar` — optional; null means "the target's own name" (the grade's name, the
 *    brand's name…), so a rail follows a rename without anybody editing it.
 *  - `card_count` — how many cards the rail shows.
 *
 * On the never-dropped list (`CoreChecksumCommand::DASHBOARD_TABLES`): a human authors it and no
 * transform can regenerate it. `hasTable` guard for switch night's `migrate`.
 *
 * The seed reproduces TODAY's Watchizer home page exactly, so deploying this changes nothing a
 * shopper sees: offers (12), featured (5), then every grade in id order (8 each). Grades with no
 * products are seeded too — an empty rail is not rendered, which is also what the browser did. Only
 * the `watchizer` storefront is seeded; Brand Fashion has no storefront site yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storefront_home_rails')) {
            return;
        }
        Schema::create('storefront_home_rails', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->string('kind', 20);
            $t->unsignedBigInteger('target_id')->nullable();
            $t->string('title_en', 120)->nullable();
            $t->string('title_ar', 120)->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedTinyInteger('card_count')->default(8);
            $t->timestamps();
            $t->index(['storefront_id', 'position']);
        });

        $storefront = DB::table('storefronts')->where('code', 'watchizer')->value('id');
        if ($storefront === null) {
            return;                                            // a fresh test database: nothing to reproduce
        }
        $now = now();
        $rows = [
            ['kind' => 'offers', 'target_id' => null, 'card_count' => 12],
            ['kind' => 'featured', 'target_id' => null, 'card_count' => 5],
        ];
        foreach (DB::table('catalog_grades')->orderBy('id')->pluck('id') as $grade) {
            $rows[] = ['kind' => 'grade', 'target_id' => $grade, 'card_count' => 8];
        }
        foreach ($rows as $i => $row) {
            DB::table('storefront_home_rails')->insert($row + [
                'storefront_id' => $storefront,
                'position' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_home_rails');
    }
};
