<?php

namespace App\Imports;

use App\Models\Shape;
use App\Models\ShapeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ShapeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = ShapeTranslation::where('shape_name' , '=' , $row[1])->orWhere('shape_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new Shape;
            $data->translateOrNew('en')->shape_name = $row[0];
            $data->translateOrNew('ar')->shape_name = $row[1];
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
