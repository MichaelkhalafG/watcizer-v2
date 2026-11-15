<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * INDEXNOW (SEO Phase 1, 2026-11-14): the addresses a change touched, waiting to be told to Bing, Yandex and the other
 * IndexNow engines. Written by every product, article and page-text save (App\Domain\Seo\IndexNow), sent in batches by
 * `indexnow:flush` (scheduled every ten minutes) — only once `INDEXNOW_ENABLED=true`, after go-live. A sent row keeps
 * the engine's answer, so the log is the table.
 *
 * Undo (core records migrations in core_migrations): DROP TABLE indexnow_queue; DELETE FROM core_migrations WHERE
 * migration = '2026_11_14_000004_indexnow_queue';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indexnow_queue', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->string('url', 700);
            $t->string('reason', 64);
            $t->timestamp('queued_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedSmallInteger('status')->nullable();
            $t->index(['storefront_id', 'sent_at'], 'inq_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexnow_queue');
    }
};
