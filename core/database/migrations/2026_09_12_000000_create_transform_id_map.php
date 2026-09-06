<?php

/*
|--------------------------------------------------------------------------
| M1b — transform_id_map (CLEAN_CORE_STUDY §2.9.2 step 4)
|--------------------------------------------------------------------------
| Records the clean id assigned to every legacy row whose id could NOT be
| preserved (new_colors, new_sizes, the synthetic cover images, the storefront
| category nodes). The transform reads it back on every run so re-runs
| converge on the same ids. Core table; never touches a legacy table.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transform_id_map', function (Blueprint $t) {
            $t->id();
            $t->string('source_table', 64);
            $t->unsignedBigInteger('source_id');
            $t->string('target_table', 64);
            $t->unsignedBigInteger('target_id');
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['source_table', 'source_id', 'target_table'], 'tim_source_target_unique');
            $t->index(['target_table', 'target_id'], 'tim_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transform_id_map');
    }
};
