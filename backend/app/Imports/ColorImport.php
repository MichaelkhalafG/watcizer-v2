<?php

namespace App\Imports;

use App\Models\Color;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ColorImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = Color::where('color_value' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new Color;
            $data->color_value = $row[0];
            $data->translateOrNew('en')->color_name = $row[1];
            $data->translateOrNew('ar')->color_name = $row[2];
            $data->save();
        }

    }

    public function rules(): array
    {
        return [
            '0' => ['required' , 'hex_color'],
            '1' => ['nullable' , 'string' , 'min:2' , 'max:255'],
            '2' => ['nullable' , 'string' , 'min:2' , 'max:255'],
        ];
    }
}
