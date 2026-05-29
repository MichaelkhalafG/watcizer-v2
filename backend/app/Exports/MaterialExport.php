<?php

namespace App\Exports;

use App\Models\Material;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MaterialExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Material::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->material_name , $item->translate('ar')->material_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Material',
            'اسم المادة',
        ];

    }
}
