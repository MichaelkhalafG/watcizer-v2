<?php

namespace App\Imports;

use App\Models\CategoryType;
use App\Models\CategoryTypeTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class CategoryTypeImport implements ToModel, WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }
    public function model(array $row)
    {
        $count = CategoryTypeTranslation::where('category_type_name' , '=' , $row[1])->orWhere('category_type_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new CategoryType;
            $data->translateOrNew('en')->category_type_name = $row[0];
            $data->translateOrNew('ar')->category_type_name = $row[1];
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
