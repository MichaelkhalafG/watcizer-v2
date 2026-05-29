<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('main_category_id')->nullable()->after('id');
            $table->unsignedBigInteger('sub_category_id')->nullable()->after('main_category_id');
            $table->unsignedBigInteger('product_type_id')->nullable()->after('sub_category_id');
            $table->foreign('main_category_id')->references('id')->on('categories')->onDelete('set null');
            $table->foreign('sub_category_id')->references('id')->on('categories')->onDelete('set null');
            $table->foreign('product_type_id')->references('id')->on('categories')->onDelete('set null');
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['main_category_id']);
            $table->dropForeign(['sub_category_id']);
            $table->dropForeign(['product_type_id']);
            $table->dropColumn(['main_category_id', 'sub_category_id', 'product_type_id']);
        });
    }
};