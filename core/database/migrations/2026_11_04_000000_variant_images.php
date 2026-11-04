<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1y — a product image may BELONG TO ONE VARIANT (Part 1, 2026-11-04: colour chosen by image).
 *
 * ── What the shopper gets ────────────────────────────────────────────────────────────────────
 *
 * The colour picker on the product page shows a swatch per variant, and the swatch is that
 * variant's own photo; choosing a colour switches the whole gallery to that variant's images.
 * Nothing new is uploaded: the images already exist on the product, and the dashboard marks which
 * of them belong to which row.
 *
 * ── Why the column sits on the IMAGE, not on the variant ─────────────────────────────────────
 *
 * The rule is "one image belongs to one variant only; an image belonging to no variant is a
 * general image of the product". That is a nullable foreign key on the image row and nothing
 * else: a list column on the variant would let two rows claim one picture, and the rule would
 * then have to be enforced by every writer instead of by the schema.
 *
 * `ON DELETE SET NULL`: a deleted variant hands its pictures back to the product rather than
 * taking them with it (a variant with any history is never deleted anyway — AGENTS §2.22).
 *
 * ── What it does NOT touch ───────────────────────────────────────────────────────────────────
 *
 * The listing cards (`ProductCards::images()`) and the header menu read the cover and the
 * second photo exactly as before and never select this column; only the product detail does.
 * That keeps the per-variant image data out of the listing index and the nav rows (the memory
 * path of L8), which is the constraint Part 1 was built under.
 *
 * ── Rebuild safety ──────────────────────────────────────────────────────────────────────────
 *
 * `catalog_product_images` is transform OUTPUT — `core:drop-clean` drops it and `2026_09_10`
 * creates it again — so this is a guarded `Schema::table`, like `image_rendition_failures`.
 * Production is never rebuilt (AGENTS §3, 2026-09-23); the guard is for the scratch copies.
 *
 * Hand-written rollback (the ledger is `core_migrations`, never `migrations`):
 *
 *   ALTER TABLE catalog_product_images DROP FOREIGN KEY cpi_variant_fk;
 *   ALTER TABLE catalog_product_images DROP INDEX cpi_variant_idx;
 *   ALTER TABLE catalog_product_images DROP COLUMN variant_id;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_04_000000_variant_images';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_product_images') || Schema::hasColumn('catalog_product_images', 'variant_id')) {
            return;
        }

        Schema::table('catalog_product_images', function (Blueprint $table): void {
            $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
            $table->index('variant_id', 'cpi_variant_idx');
            $table->foreign('variant_id', 'cpi_variant_fk')
                ->references('id')->on('catalog_product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_product_images') || ! Schema::hasColumn('catalog_product_images', 'variant_id')) {
            return;
        }

        Schema::table('catalog_product_images', function (Blueprint $table): void {
            if (self::hasForeignKey('catalog_product_images', 'cpi_variant_fk')) {
                $table->dropForeign('cpi_variant_fk');
            }
            $table->dropIndex('cpi_variant_idx');
            $table->dropColumn('variant_id');
        });
    }

    private static function hasForeignKey(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
