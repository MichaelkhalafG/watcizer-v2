<?php

namespace App\Exports;

use App\Models\Gender;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GenderExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Gender::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->gender_name , $item->translate('ar')->gender_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name gender type',
            'اسم نوع الجنس',
        ];

    }
}
