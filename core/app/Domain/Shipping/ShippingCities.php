<?php

namespace App\Domain\Shipping;

use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The governorates the shop delivers to, and what delivery to each one costs.
 *
 * ── Why this is the handover blocker, and why it writes a LEGACY table ──────────────────────
 *
 * `shipping_cities` is the only place the delivery price lives, and the Blade dashboard's
 * `shipping_city` screen was the only editor of it. Retire that screen with nothing to replace it
 * and the next time a courier raises prices the shop has to change a number with hand-typed SQL
 * against production — which is why this screen is built before the handover rather than after.
 *
 * The table is a LEGACY one, so writing it is a deliberate exception and not an oversight. Three
 * things make it a narrow one:
 *
 *  1. Core already writes shared commerce tables — `orders`, `carts`, `cart_items`, `addresses`,
 *     `order_items`, `payment_statuses` — through the DEFAULT connection. The `legacy` connection
 *     is the read-only one (`LegacyReadOnly`), and nothing here touches it. So this is the existing
 *     shared-table pattern applied to one more table, not a new kind of access.
 *  2. `shipping_cities` and `shipping_city_translations` therefore JOIN
 *     `CoreChecksumCommand::SHARED_COMMERCE_TABLES` — they leave the frozen digest, which is the
 *     honest bookkeeping: a table core writes cannot also be a table core promises never to move.
 *  3. Every write goes through this class. There is no `DB::table('shipping_cities')->update()`
 *     anywhere else, which is what makes the rule checkable rather than a convention.
 *
 * ── What the storefront reads, and what it therefore may not lose ──────────────────────────
 *
 * Exactly four fields, emitted by `CompatMeta::shippingCities()` ordered by id:
 * `{ id, name_en, name_ar, shipping_cost }`. There is no `is_active`, no sort order, no zone and no
 * free-shipping threshold — so this screen offers none, because inventing one here would be a
 * control that changes nothing the storefront reads.
 *
 * The `id` is the load-bearing field: `addresses.shipping_city_id` points at it, so ids are never
 * renumbered and a city with addresses attached is never deleted (see {@see self::delete()}).
 */
final class ShippingCities
{
    /** Both tables, so a caller cannot half-know which one it is writing. */
    public const MASTER = 'shipping_cities';

    public const TRANSLATIONS = 'shipping_city_translations';

    /** The two locales the storefront asks for. */
    public const LOCALES = ['ar', 'en'];

    /**
     * Every governorate with both names, its price, and how many addresses depend on it.
     *
     * The address count is here rather than on the screen because it is the thing that decides
     * whether a row may be deleted, and a screen that offers a delete it cannot perform is worse
     * than one that explains why up front.
     *
     * @return list<array{id: int, name_ar: string|null, name_en: string|null, shipping_cost: string, addresses: int}>
     */
    public function all(): array
    {
        $names = [];
        foreach (DB::table(self::TRANSLATIONS)->get(['shipping_city_id', 'locale', 'city_name']) as $raw) {
            $row = Row::cast($raw);
            $names[Row::int($row, 'shipping_city_id')][Row::str($row, 'locale')] = Row::nstr($row, 'city_name');
        }

        $used = [];
        foreach (
            DB::table('addresses')->whereNotNull('shipping_city_id')
                ->selectRaw('shipping_city_id, COUNT(*) AS n')->groupBy('shipping_city_id')->get() as $raw
        ) {
            $row = Row::cast($raw);
            $used[Row::int($row, 'shipping_city_id')] = Row::int($row, 'n');
        }

        $out = [];
        foreach (DB::table(self::MASTER)->orderBy('id')->get(['id', 'shipping_cost']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $out[] = [
                'id' => $id,
                'name_ar' => $names[$id]['ar'] ?? null,
                'name_en' => $names[$id]['en'] ?? null,
                // A string, not a float: this is money and it is rendered, not computed.
                'shipping_cost' => number_format(Row::nfloat($row, 'shipping_cost') ?? 0.0, 2, '.', ''),
                'addresses' => $used[$id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:191'],
            'name_en' => ['required', 'string', 'max:191'],
            /*
             * Zero is ALLOWED — free delivery to a governorate is a real offer a shop makes, and
             * refusing it would push the team back to SQL for the one case they most want. What is
             * refused is a negative price, which is not a discount but a credit nobody can honour.
             */
            'shipping_cost' => ['required', 'numeric', 'min:0', 'max:99999.99'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            $id = DB::table(self::MASTER)->insertGetId([
                'shipping_cost' => self::cost($data),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writeNames($id, $data);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            $affected = DB::table(self::MASTER)->where('id', $id)->update([
                'shipping_cost' => self::cost($data),
                'updated_at' => now(),
            ]);

            if ($affected === 0 && ! DB::table(self::MASTER)->where('id', $id)->exists()) {
                throw new RuntimeException(ManageText::t('shipping.city_gone', 'هذه المحافظة لم تعد موجودة.'));
            }

            $this->writeNames($id, $data);
        });
    }

    /**
     * Delete, unless a real address depends on it.
     *
     * The refusal is the point of the method. `addresses.shipping_city_id` carries no database
     * constraint, so the delete would SUCCEED and leave every address in that governorate pointing
     * at a row that is gone — the customer's saved address silently loses its city, and checkout
     * prices it at zero. The count is in the message because "you cannot" without "why" sends
     * somebody to ask, and the answer is always this number.
     *
     * @return array{deleted: bool, reason: string}
     */
    public function delete(int $id): array
    {
        $addresses = DB::table('addresses')->where('shipping_city_id', $id)->count();
        if ($addresses > 0) {
            return [
                'deleted' => false,
                'reason' => ManageText::t(
                    'shipping.delete_refused',
                    'لا يمكن حذف هذه المحافظة: :count عنوان عميل مرتبط بها. غيّر سعرها بدلًا من حذفها.',
                    ['count' => $addresses],
                ),
            ];
        }

        DB::transaction(function () use ($id): void {
            DB::table(self::TRANSLATIONS)->where('shipping_city_id', $id)->delete();
            DB::table(self::MASTER)->where('id', $id)->delete();
        });

        return ['deleted' => true, 'reason' => ManageText::t('shipping.city_deleted', 'تم حذف المحافظة.')];
    }

    /** @param array<string, mixed> $data */
    private static function cost(array $data): string
    {
        $value = $data['shipping_cost'] ?? 0;

        return number_format(is_numeric($value) ? (float) $value : 0.0, 2, '.', '');
    }

    /**
     * Both locales, always — an `updateOrInsert` per locale rather than delete-then-insert, so the
     * translation ids stay put. They are not a contract, but the compat payload is built by joining
     * on `shipping_city_id` and churning ids for no reason is how a cached payload ends up stale.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeNames(int $id, array $data): void
    {
        foreach (self::LOCALES as $locale) {
            $name = $data['name_'.$locale] ?? null;

            DB::table(self::TRANSLATIONS)->updateOrInsert(
                ['shipping_city_id' => $id, 'locale' => $locale],
                ['city_name' => is_string($name) ? trim($name) : ''],
            );
        }
    }
}
