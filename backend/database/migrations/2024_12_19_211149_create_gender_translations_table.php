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
        Schema::create('gender_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['gender_id', 'locale']);
            $table->foreignId('gender_id')->references('id')->on('genders')->onDelete('cascade');

            $table->string('gender_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gender_translations');
    }
};
