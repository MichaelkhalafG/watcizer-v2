<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * AN ARTICLE'S HERO PICTURE (Michael, 2026-11-17). Every article's cover showed only its alt text: the stored path
 * carried its own "/Uploads_Images/" and the address doubled it. The hero is now a photo from our own catalogue, picked
 * once and STORED, so it never changes on a rebuild (App\Domain\Content\BlogHero, `blog:heroes`):
 *   core_blogs.hero_image_id   the catalog_product_images row the picture is (NULL: an uploaded cover, or none yet)
 *   core_blogs.hero_source     auto (the rule picked it) | dashboard (the team chose it: an uploaded cover or a product
 *                              they named; never replaced by the rule)
 *   core_blogs.hero_picked_at  when it was chosen
 *   core_blog_translations.cover_alt  the picture's alt text in that language (the product's own title)
 * And every stored cover_path loses a leading "/" and "Uploads_Images/", the form ImageUrl expects (the BF legacy import
 * already stored it so; the SEO article files did not). Additive; both tables survive core:drop-clean, so each column
 * is guarded. Undo: ALTER TABLE core_blogs DROP COLUMN hero_image_id, DROP COLUMN hero_source, DROP COLUMN
 * hero_picked_at; ALTER TABLE core_blog_translations DROP COLUMN cover_alt; restore cover_path from the deploy's backup
 * table; DELETE FROM core_migrations WHERE migration = '2026_11_18_000001_blog_hero'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('core_blogs', 'hero_image_id')) {
            Schema::table('core_blogs', function (Blueprint $t): void {
                $t->unsignedBigInteger('hero_image_id')->nullable()->after('cover_path');
                $t->string('hero_source', 16)->nullable()->after('hero_image_id');
                $t->timestamp('hero_picked_at')->nullable()->after('hero_source');
            });
        }
        if (! Schema::hasColumn('core_blog_translations', 'cover_alt')) {
            Schema::table('core_blog_translations', function (Blueprint $t): void {
                $t->string('cover_alt', 255)->nullable()->after('meta_description');
            });
        }
        // the one stored form: "Product_image/x.webp", never "/Uploads_Images/Product_image/x.webp"
        DB::table('core_blogs')->where('cover_path', 'like', '/%')->update(['cover_path' => DB::raw("TRIM(LEADING '/' FROM cover_path)")]);
        DB::table('core_blogs')->where('cover_path', 'like', 'Uploads\\_Images/%')->update(['cover_path' => DB::raw("SUBSTRING(cover_path, 16)")]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('core_blogs', 'hero_image_id')) {
            Schema::table('core_blogs', function (Blueprint $t): void {
                $t->dropColumn(['hero_image_id', 'hero_source', 'hero_picked_at']);
            });
        }
        if (Schema::hasColumn('core_blog_translations', 'cover_alt')) {
            Schema::table('core_blog_translations', function (Blueprint $t): void {
                $t->dropColumn('cover_alt');
            });
        }
    }
};
