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
        Schema::create('size_type_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['size_type_id', 'locale']);
            $table->foreignId('size_type_id')->references('id')->on('size_types')->onDelete('cascade');

            $table->string('size_type_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('size_type_translations');
    }
};
