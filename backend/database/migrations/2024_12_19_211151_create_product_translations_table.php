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
        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['product_id', 'locale']);
            $table->foreignId('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->string('product_title');
            $table->string('model_name')->nullable();
            $table->string('country')->nullable();
            $table->string('stone')->nullable();
            $table->longText('long_description');
            $table->text('short_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_translations');
    }
};
