<?php

namespace App\Exports;

use App\Models\Grade;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GradeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Grade::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->grade_name , $item->translate('ar')->grade_name , $item->translate('en')->description , $item->translate('ar')->description];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Grade',
            'اسم الدرجة',
            'Description Grade',
            'وصف الدرجة',
        ];

    }
}
