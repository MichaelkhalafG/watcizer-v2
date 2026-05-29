<?php

namespace App\Imports;

use App\Models\SizeType;
use App\Models\SizeTypeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class SizeTypeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = SizeTypeTranslation::where('size_type_name' , '=' , $row[1])->orWhere('size_type_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new SizeType;
            $data->translateOrNew('en')->size_type_name = $row[0];
            $data->translateOrNew('ar')->size_type_name = $row[1];
            $data->save();
        }

    }

    public function rules(): array
    {
        return [
            '0' => ['required', 'string' , 'min:1', 'max:255'],
            '1' => ['required', 'string' , 'min:1', 'max:255'],
        ];
    }
}
