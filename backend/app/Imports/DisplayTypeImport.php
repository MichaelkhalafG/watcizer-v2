<?php

namespace App\Imports;

use App\Models\DisplayType;
use App\Models\DisplayTypeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class DisplayTypeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = DisplayTypeTranslation::where('display_type_name' , '=' , $row[1])->orWhere('display_type_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new DisplayType;
            $data->translateOrNew('en')->display_type_name = $row[0];
            $data->translateOrNew('ar')->display_type_name = $row[1];
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
