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
        Schema::create('category_type_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['category_type_id', 'locale']);
            $table->foreignId('category_type_id')->references('id')->on('category_types')->onDelete('cascade');

            $table->string('category_type_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_type_translations');
    }
};
