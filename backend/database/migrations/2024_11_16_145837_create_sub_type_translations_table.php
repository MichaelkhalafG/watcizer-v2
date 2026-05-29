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
        Schema::create('sub_type_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['sub_type_id', 'locale']);
            $table->foreignId('sub_type_id')->references('id')->on('sub_types')->onDelete('cascade');

            $table->string('sub_type_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_type_translations');
    }
};
