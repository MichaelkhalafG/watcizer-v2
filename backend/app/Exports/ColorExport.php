<?php

namespace App\Exports;

use App\Models\Color;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ColorExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Color::all();

        foreach ($data as $item) {
            $list[] = [$item->color_value , $item->translate('en')->color_name ?? '' , $item->translate('ar')->color_name ?? ''];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Color',
            'Name Color',
            'اسم اللون',
        ];

    }
}
