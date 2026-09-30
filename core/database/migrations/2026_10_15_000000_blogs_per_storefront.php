<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2b (2026-10-01): articles belong to ONE storefront (the developer's overnight brief: "Blogs, per
 * storefront"). Until now `core_blogs` had no storefront, and no storefront showed articles at all.
 * Every existing article is Watchizer's (storefront 1) — the only storefront with a site. The slug
 * stays unique across storefronts: the URL is per storefront, so a shared slug would work, but one
 * rule for every slug is simpler to explain on the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('core_blogs', 'storefront_id')) {
            return;
        }
        $first = DB::table('storefronts')->orderBy('id')->value('id');
        Schema::table('core_blogs', function (Blueprint $t) use ($first): void {
            $t->unsignedBigInteger('storefront_id')->default(is_numeric($first) ? (int) $first : 1)->after('id');
            $t->index(['storefront_id', 'published_at']);
        });
        Schema::table('core_blogs', function (Blueprint $t): void {
            $t->foreign('storefront_id')->references('id')->on('storefronts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('core_blogs', 'storefront_id')) {
            return;
        }
        Schema::table('core_blogs', function (Blueprint $t): void {
            $t->dropForeign(['storefront_id']);
            $t->dropIndex(['storefront_id', 'published_at']);
            $t->dropColumn('storefront_id');
        });
    }
};
