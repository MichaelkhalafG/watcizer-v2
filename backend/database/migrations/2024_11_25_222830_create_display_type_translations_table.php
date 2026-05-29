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
        Schema::create('display_type_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['display_type_id', 'locale']);
            $table->foreignId('display_type_id')->references('id')->on('display_types')->onDelete('cascade');

            $table->string('display_type_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('display_type_translations');
    }
};
