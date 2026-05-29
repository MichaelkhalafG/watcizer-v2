<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_type_id')->constrained();
            $table->foreignId('brand_id')->constrained();
            $table->foreignId('grade_id')->nullable()->constrained();
            $table->foreignId('sub_type_id')->nullable()->constrained();
            $table->foreignId('band_closure_id')->nullable()->constrained()->references('id')->on('closure_types');
            $table->foreignId('dial_display_type_id')->nullable()->constrained()->references('id')->on('display_types');
            $table->decimal('case_size')->nullable();
            $table->foreignId('case_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->foreignId('case_shape_id')->nullable()->constrained()->references('id')->on('shapes');
            $table->foreignId('band_material_id')->nullable()->constrained()->references('id')->on('materials');
            $table->foreignId('watch_movement_id')->nullable()->constrained()->references('id')->on('movement_types');
            $table->decimal('band_length')->nullable();
            $table->foreignId('band_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->integer('water_resistance')->nullable();
            $table->foreignId('water_resistance_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->decimal('band_width')->nullable();
            $table->foreignId('band_width_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->decimal('case_thickness')->nullable();
            $table->foreignId('case_thickness_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->foreignId('dial_case_material_id')->nullable()->constrained()->references('id')->on('materials');
            $table->foreignId('dial_glass_material_id')->nullable()->constrained()->references('id')->on('materials');
            $table->decimal('watch_height')->nullable();
            $table->foreignId('watch_height_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->decimal('watch_width')->nullable();
            $table->foreignId('watch_width_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->decimal('watch_length')->nullable();
            $table->foreignId('watch_length_size_type_id')->nullable()->constrained()->references('id')->on('size_types');
            $table->string('sku_unique')->nullable();
            $table->string('model_number')->nullable();
            $table->string('image');
            $table->string('warranty_years')->nullable();
            $table->boolean('interchangeable_dial')->nullable();
            $table->boolean('interchangeable_strap')->nullable();
            $table->string('wa_code');
            $table->decimal('average_rate')->nullable();
            $table->decimal('purchase_price');
            $table->decimal('selling_price');
            $table->decimal('sale_price_after_discount')->nullable();
            $table->decimal('percentage_discount')->nullable();
            $table->integer('stock');
            $table->integer('market_stock')->nullable();
            $table->longText('search_keywords')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('watch_box')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
