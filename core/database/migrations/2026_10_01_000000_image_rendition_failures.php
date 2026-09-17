<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1s — "this image did not come out right", recorded where the dashboard can see it (2026-09-17).
 *
 * ── The defect ───────────────────────────────────────────────────────────────────────────────
 *
 * GD's encoders can return `true` and write a ZERO-BYTE file. Measured on this host: 8 empty
 * renditions across 59 835 files. `ImagePipeline` trusted the return value, so the empty file was
 * recorded in `renditions` and served — an empty AVIF usually falls through to the next `<picture>`
 * source, but an empty WebP is a broken image in front of a customer. Nothing noticed either.
 *
 * The pipeline now removes the empty file and does not record it, so nothing broken is served. That
 * closes the customer-facing half. This column closes the other half: **somebody has to know.** A
 * product quietly carrying fewer renditions than it should is not a failure anyone will trip over,
 * and the person who can fix it — by re-uploading a better source — never finds out.
 *
 * ── Why a column, when every other "missing data" marker is DERIVED ──────────────────────────
 *
 * The six markers on the products list are computed per render, deliberately (M1p): they cannot go
 * stale while somebody is fixing the data. That works because each of them is a question SQL can
 * ask — no SKU, no price, no placement.
 *
 * "Does this image have an empty rendition?" is not. Answering it means stat()-ing several files per
 * product, which a list query cannot do at any page size worth having. So the FACT is stored, once,
 * by whoever wrote the file and watched it come out empty — and it is cleared the moment a new image
 * is written for that row, which is the same lifecycle `is_machine` has.
 *
 * `text`, not a boolean, because "which renditions failed and why" is what makes the marker
 * actionable; a bare flag would send somebody to the filesystem to find out what this column already
 * knows. NULL means "nothing went wrong", which is the overwhelming majority of rows.
 *
 * ── Rebuild safety ──────────────────────────────────────────────────────────────────────────
 *
 * `catalog_product_images` is transform OUTPUT — `core:drop-clean` drops it and `2026_09_10` creates
 * it again — so this is a guarded `Schema::table`, exactly like `import_markers` does for
 * `catalog_products.import_ref`. The guard is what lets it run on a database whose clean tables are
 * already there, and re-run harmlessly after a rebuild has recreated them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_product_images') && ! Schema::hasColumn('catalog_product_images', 'renditions_failed')) {
            Schema::table('catalog_product_images', function (Blueprint $table): void {
                $table->text('renditions_failed')->nullable()->after('renditions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_product_images') && Schema::hasColumn('catalog_product_images', 'renditions_failed')) {
            Schema::table('catalog_product_images', function (Blueprint $table): void {
                $table->dropColumn('renditions_failed');
            });
        }
    }
};
