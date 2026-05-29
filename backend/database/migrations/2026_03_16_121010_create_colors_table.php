<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول جديد منفصل عن الـ colors القديم
        if (!Schema::hasTable('new_colors')) {
            Schema::create('new_colors', function (Blueprint $table) {
                $table->id();
                $table->string('name_en');
                $table->string('name_ar');
                $table->string('hex', 7)->default('#000000');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('new_colors');
    }
};