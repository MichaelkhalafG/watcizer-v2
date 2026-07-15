<?php

namespace App\Exports;

use App\Models\Category;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithDrawings;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class CategoryExport implements FromArray , WithHeadings ,WithDrawings
{
    private $imagePaths = [];

    public function array():array
    {
        $list = [];

        $data = Category::with('parent')->orderBy('level')->orderBy('sort_order')->get();

        $num = 2;
        foreach ($data as $item) {
            $list[] = [
                optional($item->translate('en'))->name,
                optional($item->translate('ar'))->name,
                optional($item->parent)->slug,
                $item->slug,
                $item->is_active ? 1 : 0,
                'F' . $num++,
            ];
            $this->imagePaths[] = public_path('Uploads_Images/Category/' . $item->image);
        }

        return $list;
    }

    public function headings(): array
    {
        return [
            'Name Category (EN)',
            'اسم الفئة',
            'Parent Slug',
            'Slug',
            'Active',
            'Category Image',
        ];
    }

    public function drawings()
    {
        $drawings = [];
        foreach ($this->imagePaths as $index => $imagePath) {
            if (file_exists($imagePath)) {
                $drawing = new Drawing();
                $drawing->setName('Category Image');
                $drawing->setDescription('Image for category');
                $drawing->setPath($imagePath);
                $drawing->setHeight(50);
                $drawing->setCoordinates('F' . ($index + 2));
                $drawings[] = $drawing;
            }
        }

        return $drawings;
    }
}
