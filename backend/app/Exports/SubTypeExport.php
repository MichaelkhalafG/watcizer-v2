<?php

namespace App\Exports;

use App\Models\SubType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SubTypeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = SubType::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->sub_type_name , $item->translate('ar')->sub_type_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Sub type',
            'اسم النوع الفرعي',
        ];

    }
}
