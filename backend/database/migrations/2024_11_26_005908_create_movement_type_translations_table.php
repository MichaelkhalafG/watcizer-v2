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
        Schema::create('movement_type_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['movement_type_id', 'locale']);
            $table->foreignId('movement_type_id')->references('id')->on('movement_types')->onDelete('cascade');

            $table->string('movement_type_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movement_type_translations');
    }
};
