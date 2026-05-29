<?php

namespace App\Exports;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Grade;
use App\Models\Shape;
use App\Models\Feature;
use App\Models\SubType;
use App\Models\Material;
use App\Models\SizeType;
use App\Models\ClosureType;
use App\Models\DisplayType;
use App\Models\CategoryType;
use App\Models\MovementType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class AllDataSheets implements FromArray , WithHeadings , WithTitle
{
    public function array(): array
        {
            $categoryTypes = CategoryType::all()->map(fn($item) => $item->translate('en')->category_type_name);
            $brands = Brand::all()->map(fn($item) => $item->translate('en')->brand_name);
            $grades = Grade::all()->map(fn($item) => $item->translate('en')->grade_name);
            $colors = Color::all()->map(fn($item) => $item->color_value);
            $closureTypes = ClosureType::all()->map(fn($item) => $item->translate('en')->closure_type_name);
            $displayTypes = DisplayType::all()->map(fn($item) => $item->translate('en')->display_type_name);
            $sizeTypes = SizeType::all()->map(fn($item) => $item->translate('en')->size_type_name);
            $shapes = Shape::all()->map(fn($item) => $item->translate('en')->shape_name);
            $materials = Material::all()->map(fn($item) => $item->translate('en')->material_name);
            $movementTypes = MovementType::all()->map(fn($item) => $item->translate('en')->movement_type_name);
            $features = Feature::all()->map(fn($item) => $item->translate('en')->feature_name);
            $subTypes = SubType::all()->map(fn($item) => $item->translate('en')->sub_type_name);

            $maxRows = max(
                $categoryTypes->count(),
                $brands->count(),
                $grades->count(),
                $colors->count(),
                $closureTypes->count(),
                $displayTypes->count(),
                $sizeTypes->count(),
                $shapes->count(),
                $materials->count(),
                $movementTypes->count(),
                $features->count(),
                $subTypes->count()
            );

            $data = [];
            for ($i = 0; $i < $maxRows; $i++) {
                $data[] = [
                    $categoryTypes[$i] ?? '',
                    $brands[$i] ?? '',
                    $grades[$i] ?? '',
                    $colors[$i] ?? '',
                    $closureTypes[$i] ?? '',
                    $displayTypes[$i] ?? '',
                    $sizeTypes[$i] ?? '',
                    $shapes[$i] ?? '',
                    $materials[$i] ?? '',
                    $movementTypes[$i] ?? '',
                    $features[$i] ?? '',
                    $subTypes[$i] ?? '',
                ];
            }

            return $data;
        }

        public function headings(): array
        {
            return [
                'Categories',
                'Category Types',
                'Brands',
                'Grades',
                'Colors',
                'Closure Types',
                'Display Types',
                'Size Types',
                'Shapes',
                'Materials',
                'Movement Types',
                'Features',
                'Sub Types',
            ];
        }

        public function title(): string
        {
            return 'All Create a product';
        }
}
