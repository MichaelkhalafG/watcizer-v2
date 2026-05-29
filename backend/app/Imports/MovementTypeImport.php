<?php

namespace App\Imports;

use App\Models\MovementType;
use App\Models\MovementTypeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class MovementTypeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = MovementTypeTranslation::where('movement_type_name' , '=' , $row[1])->orWhere('movement_type_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new MovementType;
            $data->translateOrNew('en')->movement_type_name = $row[0];
            $data->translateOrNew('ar')->movement_type_name = $row[1];
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
