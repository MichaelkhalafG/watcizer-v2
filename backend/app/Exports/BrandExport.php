<?php

namespace App\Exports;

use App\Models\Brand;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BrandExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Brand::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->brand_name , $item->translate('ar')->brand_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Brand',
            'اسم العلامه التجاريه',
        ];

    }
}
