<?php

namespace Database\Seeders;

use App\Models\Storefront\Storefront;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Inserts the Watchizer storefront with a DETERMINISTIC id (CLEAN_CORE_STUDY §2.9.2 step 14,
 * §2.9.3): every storefront row carries an explicit id so rehearsal and production agree,
 * and Watchizer is always id 1. Idempotent; aborts loudly on any id/code disagreement.
 */
class StorefrontSeeder extends Seeder
{
    /** @var array<string, mixed> */
    public const WATCHIZER = [
        'id' => Storefront::WATCHIZER_ID,
        'code' => 'watchizer',
        'name' => 'Watchizer',
        'domain' => 'watchizereg.com',
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'EGP',
        'is_active' => true,
    ];

    public function run(): void
    {
        self::ensure(self::WATCHIZER);
    }

    /**
     * Insert or refresh one storefront row at its explicit id.
     *
     * @param  array<string, mixed>  $row  must contain 'id' and 'code'
     */
    public static function ensure(array $row): Storefront
    {
        $id = $row['id'] ?? null;
        $code = $row['code'] ?? null;

        if (! is_int($id) || $id < 1 || ! is_string($code) || $code === '') {
            throw new RuntimeException('Storefront rows must carry an explicit positive integer id and a non-empty code.');
        }

        $byId = Storefront::query()->find($id);
        if ($byId !== null && $byId->code !== $code) {
            throw new RuntimeException(sprintf(
                'storefronts.id = %d is already taken by code [%s]; expected [%s]. Refusing to seed (deterministic storefront ids).',
                $id, $byId->code, $code,
            ));
        }

        $byCode = Storefront::query()->where('code', $code)->first();
        if ($byCode !== null && (int) $byCode->id !== $id) {
            throw new RuntimeException(sprintf(
                'storefront code [%s] already exists with id %d; expected id %d. Refusing to seed (deterministic storefront ids).',
                $code, $byCode->id, $id,
            ));
        }

        if ($byId !== null) {
            // INSERT-ONLY for dashboard-owned columns (study §2.9.6, and the other half of the
            // wave-4A finding): `forceFill($row)->save()` here used to rewrite name, domain,
            // locales, default_locale, currency and is_active on EVERY transform run, so the
            // storefront-settings screen's saves would have been reverted by the next rehearsal
            // even without the drop. The transform's job is to guarantee the row EXISTS with the
            // right id and code; what it contains afterwards belongs to the dashboard.
            return $byId;
        }

        $storefront = new Storefront;
        $storefront->forceFill($row)->save();

        return $storefront;
    }
}
