<?php

namespace App\Imports;

use App\Models\SubType;
use App\Models\SubTypeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class SubTypeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = SubTypeTranslation::where('sub_type_name' , '=' , $row[1])->orWhere('sub_type_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new SubType;
            $data->translateOrNew('en')->sub_type_name = $row[0];
            $data->translateOrNew('ar')->sub_type_name = $row[1];
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
