<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "RESERVE ONLINE, PICK UP IN STORE" (click & collect, both shops, 2026-11-14) — ONE migration.
 *
 * `storefront_pickup_stores` — THE STORES a shop offers for pickup, authored on the dashboard per storefront: the name,
 * the address and the opening hours in both languages, a map link, a phone and a WhatsApp number, active or not, and
 * their order. Seeded ONCE (insert-only) with the two stores for every storefront that has none: Arkadia Mall and
 * Heliopolis (Michael, 2026-11-14: both stores for both shops), with the client's map links (Arkadia's per shop) and each SHOP's own number
 * as phone and WhatsApp (Watchizer 01551096234; Brand Fashion its main number 01014179820), and the opening hours the
 * team confirmed: every day, 10:00 to midnight (the switch refuses to turn on while an active store has none).
 *
 * `order_pickups` — one row per PICKUP order: the store chosen, a SNAPSHOT of its name, address, hours, phone and map
 * link (a later edit of the store changes only new orders — the customer's e-mails and the slip keep what they were
 * told), the reservation's status (reserved → ready → collected | expired | cancelled), the 4-digit pickup code, the
 * ready window promised at checkout (express or market, in business days), whether it is paid AT THE STORE, the contact
 * phone's key (the last ten digits: the open pay-at-store reservations one phone may hold), and when and by whom each
 * step was taken.
 *
 * `orders.fulfilment` — `delivery` (every order until now, and the default) or `pickup`.
 * `orders.address_id` — NULLABLE: a pickup order has no delivery address (no invented one). Every reader of the column
 * handles NULL (the 2026-11-14 record lists them).
 *
 * PAY AT THE STORE — a third offline payment provider `store` (OfflineProvider::STORE) with one method `store`, seeded
 * ONCE per storefront that has none, DISABLED, with the shop's cash-on-delivery amount limits copied (Michael: capped
 * like cash on delivery). The team reviews the limit and enables it on the Payments screen. An order paid this way is a
 * `cash` order (the legacy column) whose pickup row says `pay_at_store`.
 *
 * The two new tables are on CoreChecksumCommand::DASHBOARD_TABLES (never dropped by a rebuild): the stores are typed by
 * the team, and a reservation is the record of a promise made to a customer. Creation guarded by hasTable, the ALTERs
 * by hasColumn / the column's own state (the ledger is `core_migrations`).
 *
 * Rollback (never automatic; only after the code that reads these tables is gone): `down()` REFUSES while any pickup
 * order exists — `address_id` cannot become NOT NULL again over pickup orders, and dropping `order_pickups` would erase
 * the promises made to them. With none:
 *   ALTER TABLE orders MODIFY address_id BIGINT UNSIGNED NOT NULL; ALTER TABLE orders DROP INDEX orders_fulfilment_idx,
 *   DROP COLUMN fulfilment; DROP TABLE order_pickups; DROP TABLE storefront_pickup_stores; and the `store` payment rows
 *   (DELETE … FROM storefront_payment_providers WHERE provider = 'store', with their methods and labels);
 *   DELETE FROM core_migrations WHERE migration = '2026_11_14_000000_pickup';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('storefront_pickup_stores') or Schema::create('storefront_pickup_stores', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedSmallInteger('position')->default(0);       // the order the checkout lists them in
            $t->string('name_en', 120);
            $t->string('name_ar', 120);
            $t->string('address_en', 255);
            $t->string('address_ar', 255);
            $t->string('hours_en', 255)->nullable();                // free text: "Daily 11:00–23:00"
            $t->string('hours_ar', 255)->nullable();
            $t->string('map_url', 500)->nullable();                 // a https link the customer opens
            $t->string('phone', 32)->nullable();
            $t->string('whatsapp', 32)->nullable();                 // digits with the country code, for the wa.me link
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index(['storefront_id', 'is_active', 'position'], 'pickup_store_list_idx');
            $t->foreign('storefront_id', 'pickup_store_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
        });

        Schema::hasTable('order_pickups') or Schema::create('order_pickups', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedBigInteger('store_id')->nullable();         // the store row at the time; NULL once that row is gone
            // THE STORE, as the customer was told (a later edit of the store changes only new orders)
            $t->string('store_name_en', 120);
            $t->string('store_name_ar', 120);
            $t->string('store_address_en', 255);
            $t->string('store_address_ar', 255);
            $t->string('store_hours_en', 255)->nullable();
            $t->string('store_hours_ar', 255)->nullable();
            $t->string('store_map_url', 500)->nullable();
            $t->string('store_phone', 32)->nullable();
            $t->string('store_whatsapp', 32)->nullable();
            $t->string('status', 12)->default('reserved');          // reserved | ready | collected | expired | cancelled
            $t->char('code', 4);                                    // the pickup code the customer shows with the order number
            // the ready window promised at checkout: express (our own stock) or market (sourced first), in business days
            $t->boolean('pay_at_store')->default(false);            // paid at the counter, not online
            $t->string('phone_key', 16)->nullable();                // the contact phone's last ten digits
            $t->string('stock_kind', 8)->default('express');
            $t->unsignedTinyInteger('ready_min_days');
            $t->unsignedTinyInteger('ready_max_days');
            $t->dateTime('reserved_at');
            $t->dateTime('ready_at')->nullable();
            $t->unsignedBigInteger('ready_by')->nullable();
            $t->dateTime('expires_at')->nullable();                 // set at "ready": ready_at + the hold hours (+ an extension)
            $t->dateTime('extended_at')->nullable();                // the one extension, on the customer's request
            $t->unsignedBigInteger('extended_by')->nullable();
            $t->dateTime('collected_at')->nullable();
            $t->unsignedBigInteger('collected_by')->nullable();
            $t->dateTime('closed_at')->nullable();                  // expired or cancelled
            $t->timestamps();

            $t->unique('order_id', 'order_pickup_order_unique');
            $t->index(['storefront_id', 'status', 'expires_at'], 'order_pickup_state_idx');
            $t->index(['store_id', 'status'], 'order_pickup_store_idx');
            $t->index(['storefront_id', 'phone_key', 'status'], 'order_pickup_phone_idx');
            $t->foreign('storefront_id', 'order_pickup_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
            // No foreign key to the store: the team may remove a store while reservations made at it still stand (snapshot).
        });

        if (! Schema::hasColumn('orders', 'fulfilment')) {
            Schema::table('orders', function (Blueprint $t): void {
                $t->string('fulfilment', 8)->default('delivery')->after('address_id');
                $t->index(['storefront_id', 'fulfilment'], 'orders_fulfilment_idx');
            });
        }
        $address = collect(Schema::getColumns('orders'))->firstWhere('name', 'address_id');
        if (is_array($address) && $address['nullable'] === false) {
            DB::statement('ALTER TABLE orders MODIFY address_id BIGINT UNSIGNED NULL');
        }

        // pay at the store: one disabled contract and method per storefront, the COD limits copied (insert-only)
        foreach (DB::table('storefronts')->pluck('id') as $storefrontId) {
            if (DB::table('storefront_payment_providers')->where('storefront_id', $storefrontId)->where('provider', 'store')->exists()) {
                continue;
            }
            $cod = DB::table('storefront_payment_methods as m')
                ->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
                ->where('p.storefront_id', $storefrontId)->where('p.provider', 'cod')->where('m.method', 'cod')
                ->value('m.settings');
            $limits = [];
            $decoded = is_string($cod) ? json_decode($cod, true) : null;
            foreach (['min_total', 'max_total'] as $k) {
                if (is_array($decoded) && is_numeric($decoded[$k] ?? null)) {
                    $limits[$k] = $decoded[$k];
                }
            }
            $providerId = DB::table('storefront_payment_providers')->insertGetId([
                'storefront_id' => $storefrontId, 'provider' => 'store', 'is_enabled' => true, 'credentials' => null, 'settings' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $methodId = DB::table('storefront_payment_methods')->insertGetId([
                'storefront_payment_provider_id' => $providerId, 'method' => 'store', 'integration_id' => null, 'icon' => null,
                'is_enabled' => false, 'sort' => 90, 'settings' => $limits === [] ? null : json_encode($limits),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('storefront_payment_method_translations')->insert([
                ['storefront_payment_method_id' => $methodId, 'locale' => 'ar', 'label' => 'الدفع في الفرع'], // i18n-exempt: DATA
                ['storefront_payment_method_id' => $methodId, 'locale' => 'en', 'label' => 'Pay at the store'],
            ]);
        }

        // the two stores, once, for every storefront that has none (Michael, 2026-11-14)
        $stores = [
            [
                'name_en' => 'Arkadia Mall', 'name_ar' => 'أركاديا مول', // i18n-exempt: DATA
                'address_en' => '2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont',
                'address_ar' => 'الدور الثاني، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت', // i18n-exempt: DATA
                // Arkadia's map link is PER SHOP (each points at that shop's own store in the mall) — set below
                'hours_en' => 'Every day, 10 am – midnight',
                'hours_ar' => 'يوميًا من ١٠ صباحًا حتى ١٢ منتصف الليل', // i18n-exempt: DATA
            ],
            [
                'name_en' => 'Heliopolis', 'name_ar' => 'مصر الجديدة', // i18n-exempt: DATA
                'address_en' => '90 El-Thawra Street, Heliopolis',
                'address_ar' => '٩٠ شارع الثورة، مصر الجديدة', // i18n-exempt: DATA
                'map_url' => 'https://maps.app.goo.gl/c5wtDgsmLEP398ht7',
                'hours_en' => 'Every day, 10 am – midnight',
                'hours_ar' => 'يوميًا من ١٠ صباحًا حتى ١٢ منتصف الليل', // i18n-exempt: DATA
            ],
        ];
        // each shop's own number on its stores, as phone and as WhatsApp (digits with the country code, for wa.me)
        $numbers = ['watchizer' => '01551096234', 'brandfashion' => '01014179820'];
        $arkadiaMaps = ['watchizer' => 'https://maps.app.goo.gl/VyiQpq2Ajh9B6y8f6', 'brandfashion' => 'https://maps.app.goo.gl/qqdVr4ymeXva3WDF8'];
        foreach (DB::table('storefronts')->pluck('code', 'id') as $storefrontId => $code) {
            if (DB::table('storefront_pickup_stores')->where('storefront_id', $storefrontId)->exists()) {
                continue;
            }
            $number = is_string($code) ? ($numbers[$code] ?? null) : null;
            foreach ($stores as $position => $store) {
                if ($store['name_en'] === 'Arkadia Mall') {
                    $store['map_url'] = is_string($code) ? ($arkadiaMaps[$code] ?? null) : null;
                }
                DB::table('storefront_pickup_stores')->insert($store + [
                    'phone' => $number, 'whatsapp' => $number === null ? null : '2'.$number,
                    'storefront_id' => $storefrontId, 'position' => $position, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $pickups = Schema::hasColumn('orders', 'fulfilment') ? DB::table('orders')->where('fulfilment', 'pickup')->count() : 0;
        if ($pickups > 0) {
            throw new RuntimeException("Refused: {$pickups} pickup order(s) exist. address_id cannot become NOT NULL over them, and order_pickups holds what they were promised.");
        }
        if (DB::table('orders')->whereNull('address_id')->exists()) {
            throw new RuntimeException('Refused: an order without an address exists; address_id cannot become NOT NULL.');
        }
        DB::statement('ALTER TABLE orders MODIFY address_id BIGINT UNSIGNED NOT NULL');
        if (Schema::hasColumn('orders', 'fulfilment')) {
            Schema::table('orders', function (Blueprint $t): void {
                $t->dropIndex('orders_fulfilment_idx');
                $t->dropColumn('fulfilment');
            });
        }
        Schema::dropIfExists('order_pickups');
        Schema::dropIfExists('storefront_pickup_stores');
        $providers = DB::table('storefront_payment_providers')->where('provider', 'store')->pluck('id')->all();
        $methods = DB::table('storefront_payment_methods')->whereIn('storefront_payment_provider_id', $providers)->pluck('id')->all();
        DB::table('storefront_payment_method_translations')->whereIn('storefront_payment_method_id', $methods)->delete();
        DB::table('storefront_payment_methods')->whereIn('id', $methods)->delete();
        DB::table('storefront_payment_providers')->whereIn('id', $providers)->delete();
    }
};
