<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track user activity for the re-engagement campaign:
     *  - last_login_at        → updated on every (local + social) login
     *  - last_reengagement_at → set when a re-engagement email is sent, so a
     *                           user receives at most one every 30 days.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'last_reengagement_at')) {
                $table->timestamp('last_reengagement_at')->nullable()->after('last_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'last_reengagement_at']);
        });
    }
};
