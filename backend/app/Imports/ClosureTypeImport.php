<?php

namespace App\Imports;

use App\Models\ClosureType;
use App\Models\ClosureTypeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ClosureTypeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = ClosureTypeTranslation::where('closure_type_name' , '=' , $row[1])->orWhere('closure_type_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new ClosureType;
            $data->translateOrNew('en')->closure_type_name = $row[0];
            $data->translateOrNew('ar')->closure_type_name = $row[1];
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
