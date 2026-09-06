<?php

namespace App\Transform\Steps;

use App\Models\Storefront\Storefront;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Database\Seeders\StorefrontSeeder;

/**
 * Step 14 — the Watchizer storefront row at its EXPLICIT id (study §2.9.2 step 14,
 * §2.9.3): delegated to StorefrontSeeder::ensure(), which aborts on any id/code
 * disagreement. Nothing is read from legacy.
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
        $existed = Storefront::query()->whereKey(Storefront::WATCHIZER_ID)->exists();
        $storefront = StorefrontSeeder::ensure(StorefrontSeeder::WATCHIZER);
        $key = $storefront->getKey();
        $ctx->storefrontId = is_int($key) ? $key : Storefront::WATCHIZER_ID;

        if (! $existed) {
            $result->writes->inserted++;
        } elseif ($storefront->wasChanged()) {
            $result->writes->updated++;
        } else {
            $result->writes->unchanged++;
        }
        $result->note("storefront id {$ctx->storefrontId} code [{$storefront->code}]");
    }
}
