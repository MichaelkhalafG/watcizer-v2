<?php

namespace App\Domain\Inventory;

/**
 * Who caused a movement: `actor_type` + `actor_id` on `inventory_movements`.
 *
 * `actor_id` is a `users.id` for user/admin and NULL for system/erp. The ledger is the audit
 * trail for a stock number, so every movement names its author even when that author is a
 * scheduled command.
 */
final class Actor
{
    public const SYSTEM = 'system';

    public const USER = 'user';

    public const ADMIN = 'admin';

    public const ERP = 'erp';

    private function __construct(
        public readonly string $type,
        public readonly ?int $id,
    ) {}

    public static function system(): self
    {
        return new self(self::SYSTEM, null);
    }

    public static function erp(): self
    {
        return new self(self::ERP, null);
    }

    public static function user(?int $id): self
    {
        return $id === null ? self::system() : new self(self::USER, $id);
    }

    public static function admin(?int $id): self
    {
        return $id === null ? self::system() : new self(self::ADMIN, $id);
    }
}
