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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('offer_id')->nullable()->constrained();
            $table->integer('quantity');
            $table->decimal('piece_price');
            $table->decimal('total_price');
            $table->enum('type_stock', ['Express','Market'])->default('Express');
            $table->string('color_band')->nullable();
            $table->string('color_dial')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
