<?php

declare(strict_types=1);

namespace App\Domain\Pickup;

use App\Domain\Activity\ActivityLog;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The stores a shop offers for pickup (2026-11-14): read for the checkout and the dashboard, and saved from the
 * dashboard as ONE list (add, edit, reorder, remove — all or nothing; every refusal on its own field).
 *
 * A store with OPEN reservations (reserved or ready) cannot be removed — the team still has to hand those orders over
 * there — only switched off, which takes it out of the checkout and keeps it on the dashboard. A removed store's past
 * orders keep their snapshot (order_pickups).
 */
final class PickupStores
{
    public const TABLE = 'storefront_pickup_stores';

    /** Reservations still to be handed over: they keep their store. */
    public const OPEN = ['reserved', 'ready'];

    /**
     * A shop's stores in checkout order.
     *
     * @return list<array{id: int, position: int, name_en: string, name_ar: string, address_en: string, address_ar: string, hours_en: string|null, hours_ar: string|null, map_url: string|null, phone: string|null, whatsapp: string|null, is_active: bool}>
     */
    public static function forStorefront(int $storefrontId, bool $activeOnly = false): array
    {
        $q = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->orderBy('position')->orderBy('id');
        if ($activeOnly) {
            $q->where('is_active', true);
        }
        $out = [];
        foreach ($q->get() as $raw) {
            $out[] = self::shape(Row::cast($raw));
        }

        return $out;
    }

    /**
     * One ACTIVE store of this shop, or null (the checkout's check: a store of another shop, or one switched off, is not
     * a choice).
     *
     * @return array{id: int, position: int, name_en: string, name_ar: string, address_en: string, address_ar: string, hours_en: string|null, hours_ar: string|null, map_url: string|null, phone: string|null, whatsapp: string|null, is_active: bool}|null
     */
    public static function activeStore(int $storefrontId, int $storeId): ?array
    {
        $raw = DB::table(self::TABLE)->where('id', $storeId)->where('storefront_id', $storefrontId)->where('is_active', true)->first();

        return $raw === null ? null : self::shape(Row::cast($raw));
    }

    /**
     * Save a shop's whole list. A row with an `id` of this shop is updated, one without is created, a stored row missing
     * from the list is removed (refused while it has open reservations). The list's order is the checkout's order.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws ValidationException
     */
    public static function save(int $storefrontId, array $rows, bool $pickupEnabled = false): void
    {
        $existing = [];
        foreach (DB::table(self::TABLE)->where('storefront_id', $storefrontId)->get() as $raw) {
            $row = Row::cast($raw);
            $existing[Row::int($row, 'id')] = self::shape($row);
        }

        $errors = [];
        $clean = [];
        $kept = [];
        foreach ($rows as $i => $row) {
            $id = Coerce::nint($row['id'] ?? null);
            if ($id !== null && ! isset($existing[$id])) {
                $errors["stores.{$i}.id"] = ManageText::t('pickup.store_unknown', 'هذا الفرع غير موجود في هذا المتجر.');

                continue;
            }
            $required = [];
            foreach (['name_en' => 120, 'name_ar' => 120, 'address_en' => 255, 'address_ar' => 255] as $k => $max) {
                $v = trim(Coerce::str($row[$k] ?? ''));
                if ($v === '' || mb_strlen($v) > $max) {
                    $errors["stores.{$i}.{$k}"] = ManageText::t('pickup.text_required', 'مطلوب (حتى :max حرفًا).', ['max' => $max]);
                }
                $required[$k] = $v;
            }
            $optional = [];
            foreach (['hours_en' => 255, 'hours_ar' => 255] as $k => $max) {
                $v = trim(Coerce::str($row[$k] ?? ''));
                if (mb_strlen($v) > $max) {
                    $errors["stores.{$i}.{$k}"] = ManageText::t('pickup.text_long', 'حتى :max حرفًا.', ['max' => $max]);
                }
                $optional[$k] = $v === '' ? null : $v;
            }
            $map = trim(Coerce::str($row['map_url'] ?? ''));
            if ($map !== '' && (preg_match('#^https://[^\s]+$#i', $map) !== 1 || mb_strlen($map) > 500)) {
                $errors["stores.{$i}.map_url"] = ManageText::t('pickup.map_url', 'رابط الخريطة يبدأ بـ https:// (حتى 500 حرف).');
            }
            $contact = [];
            foreach (['phone', 'whatsapp'] as $k) {
                $v = trim(Coerce::str($row[$k] ?? ''));
                $digits = preg_replace('/\D+/', '', strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])) ?? ''; // i18n-exempt: DATA — Arabic-Indic digits typed
                if ($v !== '' && (strlen($digits) < 7 || strlen($digits) > 15)) {
                    $errors["stores.{$i}.{$k}"] = ManageText::t('pickup.phone_digits', 'رقم من 7 إلى 15 رقمًا.');
                }
                // WhatsApp is kept as digits with the country code (the wa.me link); a local 01… number becomes 201…
                $contact[$k] = $v === '' ? null : ($k === 'whatsapp' ? (str_starts_with($digits, '0') ? '2'.$digits : $digits) : $v);
            }
            $data = [
                'name_en' => $required['name_en'], 'name_ar' => $required['name_ar'],
                'address_en' => $required['address_en'], 'address_ar' => $required['address_ar'],
                'hours_en' => $optional['hours_en'] ?? null, 'hours_ar' => $optional['hours_ar'] ?? null,
                'map_url' => $map === '' ? null : $map,
                'phone' => $contact['phone'] ?? null, 'whatsapp' => $contact['whatsapp'] ?? null,
                'is_active' => ($row['is_active'] ?? true) === true || ($row['is_active'] ?? null) === 1 || ($row['is_active'] ?? null) === '1',
                'position' => $i,
            ];
            $clean[] = ['id' => $id, 'data' => $data];
            if ($id !== null) {
                $kept[$id] = true;
            }
        }

        $removed = array_diff(array_keys($existing), array_keys($kept));
        foreach ($removed as $id) {
            $open = DB::table('order_pickups')->where('store_id', $id)->whereIn('status', self::OPEN)->count();
            if ($open > 0) {
                $errors['stores'] = ManageText::t('pickup.store_has_open', 'لا يمكن حذف «:name»: عليه :count حجز مفتوح. أوقفه بدلًا من حذفه.', ['name' => $existing[$id]['name_ar'], 'count' => $open]);
            }
        }
        $active = count(array_filter($clean, static fn (array $c): bool => $c['data']['is_active']));
        if ($pickupEnabled && $active === 0) {
            $errors['stores'] = ManageText::t('pickup.keep_one_active', 'الاستلام من الفرع يعمل: اترك فرعًا واحدًا مفعّلًا على الأقل، أو أوقف الاستلام أولًا.');
        }
        // while pickup is ON, an active store keeps its opening hours (the switch's own rule, not to be undone by a save)
        if ($pickupEnabled) {
            foreach ($clean as $i => $c) {
                if ($c['data']['is_active'] && ($c['data']['hours_en'] === null || $c['data']['hours_ar'] === null)) {
                    $errors["stores.{$i}.hours_ar"] = ManageText::t('pickup.hours_required_on', 'الاستلام من الفرع يعمل: الفرع المفعّل يحتاج مواعيد العمل بالعربي والإنجليزي.');
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($storefrontId, $clean, $existing, $removed): void {
            foreach ($clean as $c) {
                if ($c['id'] === null) {
                    $id = (int) DB::table(self::TABLE)->insertGetId($c['data'] + ['storefront_id' => $storefrontId, 'created_at' => now(), 'updated_at' => now()]);
                    ActivityLog::record(self::TABLE, $id, ActivityLog::CREATED, [], $c['data'], label: $c['data']['name_ar'], storefrontId: $storefrontId);

                    continue;
                }
                $before = $existing[$c['id']];
                $changed = [];
                $was = [];
                foreach ($c['data'] as $k => $v) {
                    if (($before[$k] ?? null) !== $v) {
                        $changed[$k] = $v;
                        $was[$k] = $before[$k] ?? null;
                    }
                }
                if ($changed === []) {
                    continue;
                }
                DB::table(self::TABLE)->where('id', $c['id'])->update($changed + ['updated_at' => now()]);
                // the position alone moves when another row is added or removed: logged, but it is not a content change
                ActivityLog::record(self::TABLE, $c['id'], ActivityLog::UPDATED, $was, $changed, label: $c['data']['name_ar'], storefrontId: $storefrontId);
            }
            foreach ($removed as $id) {
                DB::table(self::TABLE)->where('id', $id)->delete();
                ActivityLog::record(self::TABLE, $id, ActivityLog::DELETED, $existing[$id], [], label: $existing[$id]['name_ar'], storefrontId: $storefrontId);
            }
        });
    }

    /**
     * The names (Arabic) of the stores in the list that lack opening hours in either language.
     *
     * @param  list<array{name_ar: string, hours_en: string|null, hours_ar: string|null}>  $stores
     * @return list<string>
     */
    public static function withoutHours(array $stores): array
    {
        $out = [];
        foreach ($stores as $s) {
            if (trim((string) $s['hours_en']) === '' || trim((string) $s['hours_ar']) === '') {
                $out[] = $s['name_ar'];
            }
        }

        return $out;
    }

    /** @return array{id: int, position: int, name_en: string, name_ar: string, address_en: string, address_ar: string, hours_en: string|null, hours_ar: string|null, map_url: string|null, phone: string|null, whatsapp: string|null, is_active: bool} */
    private static function shape(\stdClass $row): array
    {
        return [
            'id' => Row::int($row, 'id'),
            'position' => Row::int($row, 'position'),
            'name_en' => Row::str($row, 'name_en'),
            'name_ar' => Row::str($row, 'name_ar'),
            'address_en' => Row::str($row, 'address_en'),
            'address_ar' => Row::str($row, 'address_ar'),
            'hours_en' => Row::nstr($row, 'hours_en'),
            'hours_ar' => Row::nstr($row, 'hours_ar'),
            'map_url' => Row::nstr($row, 'map_url'),
            'phone' => Row::nstr($row, 'phone'),
            'whatsapp' => Row::nstr($row, 'whatsapp'),
            'is_active' => (bool) Row::int($row, 'is_active'),
        ];
    }
}
