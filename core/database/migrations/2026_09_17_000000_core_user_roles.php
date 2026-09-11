<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1g — wave 4A: who may use the dashboard, and as what (AGENTS §2.18, decided 2026-09-10).
 *
 * The two candidate designs were a nullable column on the shared `users` table and a core-owned
 * table. The developer picked the table, and the deciding argument was not taste:
 *
 *  • `users` is a LEGACY table (§3 forbids modifying one) that the legacy application still
 *    serialises with `toArray()`. `login`, `register` and `me` are PROXIED to that application
 *    after the switch, so a `core_role` column would have appeared in the auth JSON the LIVE
 *    Watchizer storefront consumes — a wire-format change on a production contract, and an
 *    unnecessary disclosure of who runs the shop.
 *  • A row per grant carries an audit trail (`granted_by`, `created_at`) and can be scoped to one
 *    storefront, which Brand Fashion will need the day it has its own data-entry staff.
 *
 * ── Why `user_id` has a foreign key and `storefront_id` does not ─────────────────────────────
 *
 * `users` is never dropped by any procedure in this project, so a key there is safe, and CASCADE
 * is deliberate: a legacy admin deleting a user must not be blocked by a core table (RESTRICT
 * would break a legacy operation), and a grant belonging to a deleted account is worthless.
 *
 * `storefronts`, by contrast, is a CLEAN table that switch night DROPS and rebuilds (§3.4 step 3b),
 * exactly the M1f situation: a surviving table must not hold a key into one that vanishes. The
 * column is plain and indexed, and `Roles::assign()` validates the storefront exists.
 *
 * ── Rebuild safety ───────────────────────────────────────────────────────────────────────────
 *
 * This table holds REAL grants, not transform output, so it must never join
 * `CoreChecksumCommand::CLEAN_TABLES` — the drop-and-rebuild would wipe every administrator's
 * access on switch night, at the worst possible moment. `tests/Feature/Manage/RolesTest.php`
 * asserts the exclusion by name so a future edit to that list fails the suite instead of
 * discovering it in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_user_roles')) {
            return;
        }

        Schema::create('core_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // The closed set lives in App\Domain\Access\Role; the column is a string so adding a
            // third role is a data change rather than an ALTER on a table the dashboard reads on
            // every request.
            $table->string('role', 32);
            // NULL = every storefront. A value scopes the grant to one (Brand Fashion staff who
            // must not touch Watchizer). No FK: see the class docblock.
            $table->unsignedBigInteger('storefront_id')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role', 'storefront_id'], 'cur_user_role_scope_unique');
            $table->index(['role', 'storefront_id'], 'cur_role_scope_idx');
            $table->index('storefront_id', 'cur_storefront_idx');

            $table->foreign('user_id', 'cur_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by', 'cur_granted_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Deliberately not `dropIfExists`: dropping this table locks every administrator out of the
        // dashboard, so it is only ever done on purpose. The rollback path for a role is
        // `manage:role revoke`, not a migration.
        if (Schema::hasTable('core_user_roles') && DB::table('core_user_roles')->count() === 0) {
            Schema::drop('core_user_roles');
        }
    }
};
