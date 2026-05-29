<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class ProductDetailsSheet implements FromArray , WithHeadings , WithDrawings , WithTitle
{
    private $imagePaths;

    public function array():array
    {
        $list = [];
        $imagePaths  = [];

        $data = Product::all();

        $num = 2;
        foreach ($data as $item) {

            $features = '';
            if ($item->feature) {
                foreach ($item->feature as $data) {
                    $features .= $data->translate('en')->feature_name . ' - ';
                }
                $features = rtrim($features , ' - ');
            }
            $genders = '';
            if ($item->gender) {
                foreach ($item->gender as $data) {
                    $genders .= $data->translate('en')->gender_name . ' - ';
                }
                $genders = rtrim($genders , ' - ');
            }
            $dialColors = '';
            if ($item->dialColor) {
                foreach ($item->dialColor as $data) {
                    $dialColors .= $data->color_value . ' - ';
                }
                $dialColors = rtrim($dialColors , ' - ');
            }
            $bandColors = '';
            if ($item->bandColor) {
                foreach ($item->bandColor as $data) {
                    $bandColors .= $data->color_value . ' - ';
                }
                $bandColors = rtrim($bandColors , ' - ');
            }

            $list[] = [
                'A'.$num++,
                $item->translate('ar')->product_title,
                $item->translate('en')->product_title,
                $item->sub_type ? $item->sub_type->translate('en')->sub_type_name : '',
                $item->category_type->translate('en')->category_type_name,
                $item->brand->translate('en')->brand_name,
                $item->wa_code,
                $item->purchase_price,
                $item->selling_price,
                $item->sale_price_after_discount,
                $item->percentage_discount,
                $item->stock,
                $item->active == 1 ? 'yes' : 'no',
                $item->translate('ar')->short_description,
                $item->translate('en')->short_description,
                $item->translate('ar')->long_description,
                $item->translate('en')->long_description,
                $genders,
                $features,
                $item->grade ? $item->grade->translate('en')->grade_name : '',
                $dialColors,
                $bandColors,
                $item->closure_type ? $item->closure_type->translate('en')->closure_type_name : '',
                $item->display_type ? $item->display_type->translate('en')->display_type_name : '',
                $item->case_size*1,
                $item->caseSizeType ? $item->caseSizeType->translate('en')->size_type_name : '',
                $item->shape ? $item->shape->translate('en')->shape_name : '',
                $item->bandMaterial ? $item->bandMaterial->translate('en')->material_name : '',
                $item->movement_type ? $item->movement_type->translate('en')->movement_type_name : '',
                $item->band_length*1,
                $item->bandSizeType ? $item->bandSizeType->translate('en')->size_type_name : '',
                $item->water_resistance*1,
                $item->waterResistanceSizeType ? $item->waterResistanceSizeType->translate('en')->size_type_name : '',
                $item->band_width*1,
                $item->bandWidthSizeType ? $item->bandWidthSizeType->translate('en')->size_type_name : '',
                $item->case_thickness*1,
                $item->caseThicknessSizeType ? $item->caseThicknessSizeType->translate('en')->size_type_name : '',
                $item->dialCaseMaterial ? $item->dialCaseMaterial->translate('en')->material_name : '',
                $item->dialGlassMaterial ? $item->dialGlassMaterial->translate('en')->material_name : '',
                $item->watch_height*1,
                $item->watchHeightSizeType ? $item->watchHeightSizeType->translate('en')->size_type_name : '',
                $item->watch_width*1,
                $item->watchWidthSizeType ? $item->watchWidthSizeType->translate('en')->size_type_name : '',
                $item->translate('ar')->model_name,
                $item->translate('en')->model_name,
                $item->model_number,
                $item->watch_length*1,
                $item->watchLengthSizeType ? $item->watchLengthSizeType->translate('en')->size_type_name : '',
                $item->warranty_years,
                $item->interchangeable_dial == 1 ? 'yes' : 'no',
                $item->interchangeable_strap == 1 ? 'yes' : 'no',
                $item->watch_box == 1 ? 'yes' : 'no',
                $item->sku_unique,
                $item->translate('ar')->country,
                $item->translate('en')->country,
                $item->translate('ar')->stone,
                $item->translate('en')->stone,
                $item->market_stock,
                $item->search_keywords,
            ];
            $this->imagePaths[] = public_path('Uploads_Images/Product/' . $item->image) ;
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Image',
            'عنوان المنتج',
            'Product Title',
            'Sub Type',
            'Category Type',
            'Brand',
            'wa code',
            'Purchase Price',
            'Selling Price',
            'Sale price after discount',
            'Percentage discount',
            'Stock',
            'Is Active ?',
            'وصف مختصر',
            'Short Description',
            'وصف طويل',
            'Long Description',
            'Genders',
            'Features',
            'Grade',
            'Dial Color',
            'Band Color',
            'Closure Type',
            'Display Type',
            'Case Size',
            'Case Size Unit',
            'Case Shape',
            'Band Material',
            'Watch Movement',
            'Band Size',
            'Band Size Unit',
            'Water Resistance Size',
            'Water Resistance Size Unit',
            'Band Width Size',
            'Band Width Size Unit',
            'Case Thickness Size',
            'Case Thickness Size Unit',
            'Dial Case Material',
            'Dial Glass Material',
            'Watch Height Size',
            'Watch Height Size Unit',
            'Watch Width Size',
            'Watch Width Size Unit',
            'اسم الموديل',
            'Model Name',
            'Model Number',
            'Watch Length Size',
            'Watch Length Size Unit',
            'Warranty Years',
            'Is Interchangeable Dial ?',
            'Is Interchangeable Strap ?',
            'Is Watch Box ?',
            'SKU Unique',
            'بلد الصنع',
            'Country of manufacture',
            'نوع الحجر',
            'Stone Type',
            'Market Stock',
            'Search Keywords',
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
                $drawing->setCoordinates('A' . ($index + 2)); // Start from column F, row 2
                $drawings[] = $drawing;
            }
        }

        return $drawings;
    }

    public function title(): string
    {
        return 'Product Details';
    }
}
