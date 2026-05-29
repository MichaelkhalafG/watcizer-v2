<?php

namespace App\Imports;

use App\Models\Grade;
use App\Models\GradeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class GradeImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = GradeTranslation::where('grade_name' , '=' , $row[1])->orWhere('grade_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new Grade;
            $data->translateOrNew('en')->grade_name  = $row[0];
            $data->translateOrNew('ar')->grade_name  = $row[1];
            $data->translateOrNew('en')->description = $row[2];
            $data->translateOrNew('ar')->description = $row[3];
            $data->save();
        }
    }

    public function rules(): array
    {
        return [
            '0' => ['required', 'string' , 'min:2', 'max:255'],
            '1' => ['required', 'string' , 'min:2', 'max:255'],
            '2' => ['required', 'string'],
            '3' => ['required', 'string'],
        ];
    }

}
