<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'status')) {
                $table->enum('status', ['draft', 'published', 'archived'])->default('published')->after('active');
            }
            if (!Schema::hasColumn('products', 'tags')) {
                $table->string('tags')->nullable()->after('search_keywords');
            }
            if (!Schema::hasColumn('products', 'views_count')) {
                $table->unsignedInteger('views_count')->default(0)->after('average_rate');
            }
        });

        // ── Database indexes for performance ──────────────
        Schema::table('products', function (Blueprint $table) {
            // بنشوف الـ indexes الموجودة قبل ما نضيف
            try { $table->index('active', 'idx_products_active'); } catch (\Exception $e) {}
            try { $table->index('brand_id', 'idx_products_brand'); } catch (\Exception $e) {}
            try { $table->index('main_category_id', 'idx_products_main_cat'); } catch (\Exception $e) {}
            try { $table->index('sub_category_id', 'idx_products_sub_cat'); } catch (\Exception $e) {}
            try { $table->index('created_at', 'idx_products_created'); } catch (\Exception $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = ['status', 'tags', 'views_count'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};