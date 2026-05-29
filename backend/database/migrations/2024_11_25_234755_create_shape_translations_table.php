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
        Schema::create('shape_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['shape_id', 'locale']);
            $table->foreignId('shape_id')->references('id')->on('shapes')->onDelete('cascade');

            $table->string('shape_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shape_translations');
    }
};
