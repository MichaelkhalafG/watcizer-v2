<?php

namespace App\Exports;

use App\Models\Feature;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class FeatureExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = Feature::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->feature_name , $item->translate('ar')->feature_name];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Feature',
            'اسم الميزة',
        ];

    }
}
