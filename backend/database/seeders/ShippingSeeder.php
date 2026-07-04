<?php

namespace Database\Seeders;

use App\Models\ShippingCity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShippingSeeder extends Seeder
{
    /**
     * Seed the 27 Egyptian governorates into the translatable shipping_cities
     * table (+ shipping_city_translations). Idempotent: matches existing rows by
     * the English city name so re-running only updates costs/translations.
     */
    public function run(): void
    {
        // [cost (EGP), English name, Arabic name]
        $cities = [
            // 100 — Cairo area
            [100, 'Cairo', 'القاهرة'],
            [100, 'Giza', 'الجيزة'],
            [100, 'Qalyubia', 'القليوبية'],
            // 130 — Near Delta
            [130, 'Menoufia', 'المنوفية'],
            [130, 'Gharbia', 'الغربية'],
            [130, 'Sharqia', 'الشرقية'],
            [130, 'Dakahlia', 'الدقهلية'],
            // 150 — Extended Delta + Canal
            [150, 'Beheira', 'البحيرة'],
            [150, 'Kafr El Sheikh', 'كفر الشيخ'],
            [150, 'Damietta', 'دمياط'],
            [150, 'Ismailia', 'الإسماعيلية'],
            [150, 'Port Said', 'بورسعيد'],
            [150, 'Suez', 'السويس'],
            // 170 — Alexandria + nearby
            [170, 'Alexandria', 'الإسكندرية'],
            [170, 'Fayoum', 'الفيوم'],
            [170, 'Beni Suef', 'بني سويف'],
            [170, 'Matrouh', 'مطروح'],
            // 200 — Upper Egypt North
            [200, 'Minya', 'المنيا'],
            [200, 'Asyut', 'أسيوط'],
            [200, 'Sohag', 'سوهاج'],
            // 250 — Upper Egypt South
            [250, 'Qena', 'قنا'],
            [250, 'Luxor', 'الأقصر'],
            [250, 'Aswan', 'أسوان'],
            [250, 'New Valley', 'الوادي الجديد'],
            // 300 — Remote
            [300, 'Red Sea', 'البحر الأحمر'],
            [300, 'North Sinai', 'شمال سيناء'],
            [300, 'South Sinai', 'جنوب سيناء'],
        ];

        foreach ($cities as [$cost, $en, $ar]) {
            $existingId = DB::table('shipping_city_translations')
                ->where('locale', 'en')
                ->where('city_name', $en)
                ->value('shipping_city_id');

            $city = $existingId ? ShippingCity::find($existingId) : new ShippingCity();
            $city->shipping_cost = $cost;
            $city->save();

            $city->translateOrNew('en')->city_name = $en;
            $city->translateOrNew('ar')->city_name = $ar;
            $city->save();
        }

        $this->command->info('Seeded ' . count($cities) . ' shipping governorates.');
    }
}
