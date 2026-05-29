<?php

namespace App\Exports;

use App\Models\DisplayType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class DisplayTypeExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = DisplayType::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->display_type_name , $item->translate('ar')->display_type_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Display Type',
            'اسم نوع العرض',
        ];

    }
}
