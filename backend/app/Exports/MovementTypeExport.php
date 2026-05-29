<?php

namespace App\Exports;

use App\Models\MovementType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MovementTypeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = MovementType::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->movement_type_name , $item->translate('ar')->movement_type_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Movement Type',
            'اسم نوع الحركة',
        ];

    }
}
