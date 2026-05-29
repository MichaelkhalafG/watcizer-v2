<?php

namespace App\Imports;

use App\Models\ShippingCity;
use App\Models\ShippingCityTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ShippingCityImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = ShippingCityTranslation::where('city_name' , '=' , $row[1])->orWhere('city_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new ShippingCity;
            $data->translateOrNew('en')->city_name  = $row[0];
            $data->translateOrNew('ar')->city_name  = $row[1];
            $data->shipping_cost                            = $row[2];
            $data->save();
        }
    }

    public function rules(): array
    {
        return [
            '0' => ['required', 'string' , 'min:2', 'max:255'],
            '1' => ['required', 'string' , 'min:2', 'max:255'],
            '2' => ['required', 'numeric'],
        ];
    }
}
