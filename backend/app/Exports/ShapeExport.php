<?php

namespace App\Exports;

use App\Models\Shape;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ShapeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Shape::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->shape_name , $item->translate('ar')->shape_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Shape',
            'اسم الشكل',
        ];

    }
}
