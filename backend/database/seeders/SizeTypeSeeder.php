<?php
namespace Database\Seeders;
use App\Models\SizeType;
use Illuminate\Database\Seeder;
class SizeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $existing = SizeType::with('translations')->get()
            ->flatMap(fn($s) => $s->translations->pluck('size_type_name'))
            ->map(fn($n) => strtolower($n))
            ->toArray();

        $sizes = [
            ['en' => 'XS',        'ar' => 'XS'],
            ['en' => 'S',         'ar' => 'S'],
            ['en' => 'M',         'ar' => 'M'],
            ['en' => 'L',         'ar' => 'L'],
            ['en' => 'XL',        'ar' => 'XL'],
            ['en' => 'XXL',       'ar' => 'XXL'],
            ['en' => 'XXXL',      'ar' => 'XXXL'],
            ['en' => 'XXXXL',     'ar' => 'XXXXL'],
            ['en' => 'XXXXXL',    'ar' => 'XXXXXL'],
            ['en' => '26',        'ar' => '26'],
            ['en' => '27',        'ar' => '27'],
            ['en' => '28',        'ar' => '28'],
            ['en' => '29',        'ar' => '29'],
            ['en' => '30',        'ar' => '30'],
            ['en' => '31',        'ar' => '31'],
            ['en' => '32',        'ar' => '32'],
            ['en' => '33',        'ar' => '33'],
            ['en' => '34',        'ar' => '34'],
            ['en' => '35',        'ar' => '35'],
            ['en' => '36',        'ar' => '36'],
            ['en' => '37',        'ar' => '37'],
            ['en' => '38',        'ar' => '38'],
            ['en' => '39',        'ar' => '39'],
            ['en' => '40',        'ar' => '40'],
            ['en' => '41',        'ar' => '41'],
            ['en' => '42',        'ar' => '42'],
            ['en' => '43',        'ar' => '43'],
            ['en' => '44',        'ar' => '44'],
            ['en' => '45',        'ar' => '45'],
            ['en' => '46',        'ar' => '46'],
            ['en' => '47',        'ar' => '47'],
            ['en' => 'mm',        'ar' => 'مم'],
            ['en' => 'cm',        'ar' => 'سم'],
            ['en' => 'inch',      'ar' => 'إنش'],
            ['en' => 'ATM',       'ar' => 'ATM'],
            ['en' => 'Bar',       'ar' => 'Bar'],
            ['en' => 'Free Size', 'ar' => 'مقاس حر'],
        ];

        $added = 0;
        foreach ($sizes as $size) {
            if (in_array(strtolower($size['en']), $existing)) continue;
            $item = SizeType::create([]);
            $item->translateOrNew('en')->size_type_name = $size['en'];
            $item->translateOrNew('ar')->size_type_name = $size['ar'];
            $item->save();
            $added++;
        }

        $this->command->info("✅ Added {$added} size types. Total: " . SizeType::count());
    }
}