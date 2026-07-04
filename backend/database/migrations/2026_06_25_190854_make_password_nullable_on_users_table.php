<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Social-only accounts (Google / Microsoft) have no password, so the column
     * must be nullable. Raw SQL avoids a doctrine/dbal dependency for ->change().
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Revert to NOT NULL. Any rows with NULL password would block this, so
        // backfill an empty string first to keep the rollback safe.
        DB::statement("UPDATE users SET password = '' WHERE password IS NULL");
        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
    }
};
