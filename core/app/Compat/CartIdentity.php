<?php

namespace App\Compat;

/**
 * Who a cart belongs to: exactly one of a `users.id` or a guest UUID, which is what the legacy
 * `carts` table models (`user_id` XOR `guest_token`, both nullable).
 *
 * The legacy code passed this around as `['user_id' => n]` / `['guest_token' => s]` and fed the
 * array straight into `Cart::where(...)`. Making it a type removes the two ways that went wrong:
 * an empty array matching every cart, and a client-supplied `identity` reaching the query.
 */
final class CartIdentity
{
    private function __construct(
        public readonly ?int $userId,
        public readonly ?string $guestToken,
    ) {}

    public static function user(int $userId): self
    {
        return new self($userId, null);
    }

    public static function guest(string $guestToken): self
    {
        return new self(null, $guestToken);
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /** The column/value pair that selects this owner's cart. */
    public function column(): string
    {
        return $this->isGuest() ? 'guest_token' : 'user_id';
    }

    public function value(): int|string
    {
        return $this->isGuest() ? (string) $this->guestToken : (int) $this->userId;
    }
}
