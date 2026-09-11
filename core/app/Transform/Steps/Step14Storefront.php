<?php

namespace App\Transform\Steps;

use App\Models\Storefront\Storefront;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Database\Seeders\StorefrontSeeder;

/**
 * Step 14 — the storefront rows at their EXPLICIT ids (study §2.9.2 step 14, §2.9.3):
 * delegated to StorefrontSeeder::ensure(), which aborts on any id/code disagreement. Nothing is
 * read from legacy.
 *
 * Since 2026-09-11 this seeds BOTH storefronts — Watchizer (1) and Brand Fashion (2) — because
 * everything downstream of it is now per-storefront: the category tree is mirrored per storefront
 * (steps 15–17), and every product gets a `storefront_product` row and its placements on EVERY
 * ACTIVE storefront (steps 18–19). `$ctx->storefrontId` stays the PRIMARY storefront: the steps
 * that iterate do so over `$ctx->activeStorefrontIds()` and restore it afterwards, so a step that
 * does not iterate keeps behaving exactly as it did.
 *
 * `ensure()` is insert-only for everything but id and code (§2.20), so deactivating Brand Fashion
 * from the settings screen STAYS deactivated — and `activeStorefrontIds()` then stops writing rows
 * for it, which is the point of reading the list from the database rather than from a constant.
 */
final class Step14Storefront implements Step
{
    public function number(): int
    {
        return 14;
    }

    public function name(): string
    {
        return 'storefront';
    }

    public function target(): string
    {
        return 'storefronts';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $notes = [];
        foreach (StorefrontSeeder::all() as $row) {
            $id = is_int($row['id'] ?? null) ? $row['id'] : 0;
            $existed = $id > 0 && Storefront::query()->whereKey($id)->exists();
            $storefront = StorefrontSeeder::ensure($row);
            $result->read++;

            if (! $existed) {
                $result->writes->inserted++;
            } elseif ($storefront->wasChanged()) {
                $result->writes->updated++;
            } else {
                $result->writes->unchanged++;
            }
            $notes[] = sprintf('%d [%s]%s', $storefront->id, $storefront->code, $storefront->is_active ? '' : ' INACTIVE');
        }

        // The PRIMARY storefront, which is what a step that does not iterate means by
        // `$ctx->storefrontId`. The iterating steps loop over `activeStorefrontIds()`.
        $ctx->storefrontId = Storefront::WATCHIZER_ID;
        $result->note('storefronts: '.implode(', ', $notes).'; active: '.implode(', ', $ctx->activeStorefrontIds()));
    }
}
