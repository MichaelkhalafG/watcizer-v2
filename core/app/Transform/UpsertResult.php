<?php

namespace App\Transform;

final class UpsertResult
{
    public int $inserted = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public function add(self $other): void
    {
        $this->inserted += $other->inserted;
        $this->updated += $other->updated;
        $this->unchanged += $other->unchanged;
    }

    public function total(): int
    {
        return $this->inserted + $this->updated + $this->unchanged;
    }
}
