<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PAGE TEXTS FOR PHASE 2 (2026-11-14): every brand, category and LANDING page text (`subject_type` 'landing', keyed
 * `brand/family/gender`) gains
 *   - `intro`: the 60–90 words shown ABOVE the products (the existing `description` stays the guide under them);
 *   - the QUALITY GATE's verdict on that language's text: `gate_status` ('passed' | 'held'), `gate_report` (the reasons,
 *     JSON) and `gate_checked_at`. A landing page is indexable only when both languages passed; any save clears the
 *     verdict, so a changed text is checked again (App\Domain\Content\SeoTexts::save).
 *
 * Undo (core records migrations in core_migrations): ALTER TABLE core_seo_texts DROP COLUMN intro, DROP COLUMN
 * gate_status, DROP COLUMN gate_report, DROP COLUMN gate_checked_at; DELETE FROM core_migrations WHERE migration =
 * '2026_11_14_000006_page_text_intro_and_gate';
 */
return new class extends Migration
{
    public function up(): void
    {
        // guarded: core_seo_texts survives core:drop-clean while the migration ledger does not (RebuildSurvivesTest)
        if (Schema::hasColumn('core_seo_texts', 'intro')) {
            return;
        }
        Schema::table('core_seo_texts', function (Blueprint $t): void {
            $t->text('intro')->nullable()->after('locale');
            $t->string('gate_status', 8)->nullable()->after('meta_description');
            $t->text('gate_report')->nullable()->after('gate_status');
            $t->timestamp('gate_checked_at')->nullable()->after('gate_report');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('core_seo_texts', 'intro')) {
            return;
        }
        Schema::table('core_seo_texts', function (Blueprint $t): void {
            $t->dropColumn(['intro', 'gate_status', 'gate_report', 'gate_checked_at']);
        });
    }
};
