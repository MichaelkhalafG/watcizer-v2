<?php

namespace App\Exports;

use App\Models\ClosureType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClosureTypeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = ClosureType::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->closure_type_name , $item->translate('ar')->closure_type_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Closure Type',
            'اسم نوع الإغلاق',
        ];

    }
}
