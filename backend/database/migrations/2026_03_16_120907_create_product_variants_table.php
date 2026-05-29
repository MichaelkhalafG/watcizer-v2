<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->foreignId('color_id')->nullable()->constrained('new_colors')->nullOnDelete();
                $table->foreignId('size_id')->nullable()->constrained('new_sizes')->nullOnDelete();
                $table->integer('stock')->default(0);
                $table->string('sku')->nullable()->unique();
                $table->decimal('price_modifier', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};