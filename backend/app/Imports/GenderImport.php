<?php

namespace App\Imports;

use App\Models\Gender;
use App\Models\GenderTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class GenderImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = GenderTranslation::where('gender_name' , '=' , $row[1])->orWhere('gender_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new Gender;
            $data->translateOrNew('en')->gender_name = $row[0];
            $data->translateOrNew('ar')->gender_name = $row[1];
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
