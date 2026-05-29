<?php

namespace App\Exports;

use App\Models\ShippingCity;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ShippingCityExport implements FromArray , WithHeadings
{
    public function array():array
    {
        $list = [];

        $data = ShippingCity::all();

        foreach ($data as $item) {
            $list[] = [$item->translate('en')->city_name , $item->translate('ar')->city_name , $item->shipping_cost];
        }

        return $list;
    }

    public function headings(): array
    {

        return [
            'Name Shipping City',
            'اسم مدينة الشحن',
            'Shipping Cost',
        ];

    }
}
