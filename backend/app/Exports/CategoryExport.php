<?php

namespace App\Exports;

use App\Models\Category;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithDrawings;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class CategoryExport implements FromArray , WithHeadings ,WithDrawings
{
    private $imagePaths;

    public function array():array
    {
        $list = [];
        $imagePaths  = [];

        $data = Category::all();

        $num = 2;
        foreach ($data as $item) {
            $list[] = [
                $item->translate('en')->category_name,
                $item->translate('ar')->category_name,
                $item->color_value,
                'D'.$num++,
            ];
            $this->imagePaths[] = public_path('Uploads_Images/Category/' . $item->category_image) ;
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Category',
            'اسم الفئة',
            'Color Value',
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
                $drawing->setPath($imagePath); // Path to the image file
                $drawing->setHeight(50); // Adjust the image height as needed
                $drawing->setCoordinates('D' . ($index + 2)); // Start from column F, row 2
                $drawings[] = $drawing;
            }
        }

        return $drawings;
    }
}
