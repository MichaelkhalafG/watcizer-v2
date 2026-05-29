<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('seo_title')->nullable()->after('search_keywords');
            $table->string('seo_slug')->nullable()->after('seo_title');
            $table->text('seo_meta_description')->nullable()->after('seo_slug');
            $table->unsignedSmallInteger('low_stock_threshold')->default(5)->after('market_stock');
            $table->json('extra_attributes')->nullable()->after('low_stock_threshold');
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['seo_title','seo_slug','seo_meta_description','low_stock_threshold','extra_attributes']);
        });
    }
};