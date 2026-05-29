<?php

namespace App\Exports;

use App\Models\SizeType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SizeTypeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = SizeType::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->size_type_name , $item->translate('ar')->size_type_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Size Unit',
            'اسم وحدة الحجم',
        ];

    }
}
