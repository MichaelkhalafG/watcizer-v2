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
        Schema::create('shipping_city_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['shipping_city_id', 'locale']);
            $table->foreignId('shipping_city_id')->references('id')->on('shipping_cities')->onDelete('cascade');

            $table->string('city_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_city_translations');
    }
};
