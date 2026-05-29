<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\BrandTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class BrandImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = BrandTranslation::where('brand_name' , '=' , $row[1])->orWhere('brand_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new Brand;
            $data->translateOrNew('en')->brand_name = $row[0];
            $data->translateOrNew('ar')->brand_name = $row[1];
            $data->save();
        }

    }

    public function rules(): array
    {
        return [
            '0' => ['required', 'string' , 'min:2', 'max:255'],
            '1' => ['required', 'string' , 'min:2', 'max:255'],
        ];
    }

}
