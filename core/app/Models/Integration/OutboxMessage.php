<?php

namespace App\Models\Integration;

use Illuminate\Database\Eloquent\Model;

/**
 * `integration_outbox` — transactional outbox for external systems (Morabaa ERP, search, webhooks).
 * Written in the same transaction as the domain change; drained by the scheduler (§5.2.1).
 */
class OutboxMessage extends Model
{
    public const UPDATED_AT = null;

    public const STATUSES = ['pending', 'sent', 'failed', 'skipped'];

    protected $table = 'integration_outbox';

    /** @var list<string> */
    protected $fillable = [
        'channel', 'event', 'aggregate_type', 'aggregate_id', 'payload', 'status', 'attempts',
        'available_at', 'processed_at', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
