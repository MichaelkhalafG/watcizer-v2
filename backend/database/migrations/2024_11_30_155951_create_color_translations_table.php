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
        Schema::create('color_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['color_id', 'locale']);
            $table->foreignId('color_id')->references('id')->on('colors')->onDelete('cascade');

            $table->string('color_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('color_translations');
    }
};
