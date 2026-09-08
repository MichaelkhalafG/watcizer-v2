<?php

/*
|--------------------------------------------------------------------------
| M1d — one primary placement per (storefront, product)   [milestone audit 🔴-2]
|--------------------------------------------------------------------------
| `storefront_category_product.is_primary` carries the product's canonical
| category: the compat layer emits it as `sub_type_id`, the v2 card as
| `primary_category`, and the sitemap/breadcrumb follow it. Nothing enforced
| that a product had exactly ONE primary row, and the transform is additive:
| when a product's legacy sub type changes (the audit's Sport → Chronograph
| case) the old placement keeps is_primary = 1 and the new one adds a second,
| so the emitted category became order-dependent.
|
| A virtual column makes the rule a database invariant: `primary_guard` is the
| product id on a primary row and NULL otherwise, and NULLs are ignored by a
| unique index — so (storefront_id, primary_guard) permits any number of
| non-primary placements and at most one primary per product per storefront.
| Verified on MariaDB 10.4 (local) and supported on 11.8 (production).
|
| Any pre-existing violation is demoted first, deterministically: the DEEPEST
| primary node wins (a sub type beats its category type), ties by lowest node
| id — the same order App\Transform\Steps\Step19Placements now writes and the
| read layers now select. Rows are never deleted; only the flag is corrected.
*/

use App\Transform\Row;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->demoteExtraPrimaries();

        Schema::table('storefront_category_product', function (Blueprint $t) {
            $t->unsignedBigInteger('primary_guard')->nullable()->virtualAs('CASE WHEN is_primary = 1 THEN product_id END');
            $t->unique(['storefront_id', 'primary_guard'], 'scp_one_primary_unique');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_category_product', function (Blueprint $t) {
            $t->dropUnique('scp_one_primary_unique');
            $t->dropColumn('primary_guard');
        });
    }

    /** Keep the deepest (then lowest-id) primary row per (storefront, product); demote the rest. */
    private function demoteExtraPrimaries(): void
    {
        $rows = DB::table('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->where('scp.is_primary', 1)
            ->orderBy('scp.storefront_id')->orderBy('scp.product_id')
            ->orderByDesc('c.depth')->orderBy('c.id')
            ->get(['scp.id', 'scp.storefront_id', 'scp.product_id']);

        $keep = [];
        $demote = [];
        foreach ($rows as $row) {
            $key = Row::int($row, 'storefront_id').':'.Row::int($row, 'product_id');
            if (isset($keep[$key])) {
                $demote[] = Row::int($row, 'id');

                continue;
            }
            $keep[$key] = true;
        }
        foreach (array_chunk($demote, 500) as $chunk) {
            DB::table('storefront_category_product')->whereIn('id', $chunk)->update(['is_primary' => 0]);
        }
    }
};
