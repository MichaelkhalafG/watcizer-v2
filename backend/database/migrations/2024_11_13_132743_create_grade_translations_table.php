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
        Schema::create('grade_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['grade_id', 'locale']);
            $table->foreignId('grade_id')->references('id')->on('grades')->onDelete('cascade');

            $table->string('grade_name');
            $table->text('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_translations');
    }
};
