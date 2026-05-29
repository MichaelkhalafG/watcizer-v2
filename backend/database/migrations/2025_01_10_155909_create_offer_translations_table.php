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
        Schema::create('offer_translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale')->index();
            $table->unique(['offer_id', 'locale']);
            $table->foreignId('offer_id')->references('id')->on('offers')->onDelete('cascade');

            $table->string('offer_name');
            $table->longText('long_description');
            $table->text('short_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_translations');
    }
};
