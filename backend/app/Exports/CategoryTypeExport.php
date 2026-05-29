<?php

namespace App\Exports;

use App\Models\CategoryType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CategoryTypeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = CategoryType::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->category_type_name , $item->translate('ar')->category_type_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Category Type',
            'اسم نوع الفئة',
        ];

    }
}
