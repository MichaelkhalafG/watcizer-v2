<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-device banners with a crop (F1, the developer's decision 2026-10-20).
 *
 *  - `device` — `both` | `desktop` | `mobile`. ONE column on the banner row, by decision: one banner is
 *    one row, and "the same artwork tuned twice" is two rows, not a second image column. Every existing
 *    row becomes `both`, which is exactly what they do today, so nothing the client has set changes.
 *  - `image_original_path` — the upload as it arrived, kept beside the cropped file that `image_path`
 *    serves, so a banner can be re-cropped later without re-uploading. Null for every row written
 *    before this (and for any written without a crop): the form then falls back to `image_path`.
 *  - `crop` — the rectangle the tool last used, as JSON `{x, y, w, h}` in the ORIGINAL's pixels, so the
 *    tool reopens where the operator left it. Null means "never cropped here".
 *
 * Additive, nullable (or defaulted), `hasColumn`-guarded, and reversible: `down()` drops the three and
 * leaves `image_path` — the file the storefront actually serves — untouched. No backfill, so no image
 * can be lost. The shared `storefront_banners` table is Watchizer's too; its storefront renders no
 * banner at all, and `both` is what its rows (today: none) would mean anyway.
 *
 * NOTE for the deploy: `type_show` (`mob | pc`, the legacy column) is NOT reused and NOT touched. It is
 * unwritten by this application and NULL on every production row (measured 2026-10-20 through v2 meta,
 * both storefronts). Retiring it is its own decision — backlog BANNER-TYPE-SHOW.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_banners', function (Blueprint $t): void {
            if (! Schema::hasColumn('storefront_banners', 'device')) {
                $t->string('device', 8)->default('both')->after('placement');
            }
            if (! Schema::hasColumn('storefront_banners', 'image_original_path')) {
                $t->string('image_original_path', 255)->nullable()->after('image_path');
            }
            if (! Schema::hasColumn('storefront_banners', 'crop')) {
                $t->text('crop')->nullable()->after('image_original_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storefront_banners', function (Blueprint $t): void {
            foreach (['device', 'image_original_path', 'crop'] as $column) {
                if (Schema::hasColumn('storefront_banners', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
