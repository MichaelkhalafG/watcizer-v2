<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            if (!Schema::hasColumn('product_images', 'is_cover')) {
                $table->boolean('is_cover')->default(false)->after('image');
            }
            if (!Schema::hasColumn('product_images', 'sort')) {
                $table->unsignedSmallInteger('sort')->default(0)->after('is_cover');
            }
            if (!Schema::hasColumn('product_images', 'alt_ar')) {
                $table->string('alt_ar')->nullable()->after('sort');
            }
            if (!Schema::hasColumn('product_images', 'alt_en')) {
                $table->string('alt_en')->nullable()->after('alt_ar');
            }
        });
    }
    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['is_cover', 'sort', 'alt_ar', 'alt_en']);
        });
    }
};