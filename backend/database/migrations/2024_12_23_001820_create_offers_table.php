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
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_product_id')->constrained()->references('id')->on('products')->cascadeOnDelete();
            $table->foreignId('category_type_id')->constrained();
            $table->json('gift_product_ids');
            $table->decimal('selling_price');
            $table->decimal('sale_price_after_discount')->nullable();
            $table->string('image');
            $table->integer('stock');
            $table->string('wa_code');
            $table->enum('in_season' , ['yes' , 'no'])->default('no');
            $table->decimal('average_rate')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
