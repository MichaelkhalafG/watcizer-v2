<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M2d — how an article's body is written (2026-10-02, the blog editor, developer's option A).
 *
 *  - `core_blogs.body_format` — 'text' or 'markdown'. Every article that exists today is 'text' (the
 *    default): plain paragraphs, "## " subheadings and "- " lists, shown exactly as before by the
 *    storefront's ArticleBody. The new editor saves 'markdown', after the team has seen its preview —
 *    so no live article changes how it looks until somebody edits and saves it.
 *
 * Additive, with a default; `hasColumn`-guarded like every M-series migration (core_blogs is a
 * dashboard table and survives the switch-night rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('core_blogs', 'body_format')) {
            Schema::table('core_blogs', function (Blueprint $t): void {
                $t->string('body_format', 10)->default('text')->after('cover_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('core_blogs', 'body_format')) {
            Schema::table('core_blogs', fn (Blueprint $t) => $t->dropColumn('body_format'));
        }
    }
};
