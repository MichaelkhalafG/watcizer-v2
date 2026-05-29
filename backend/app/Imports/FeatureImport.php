<?php

namespace App\Imports;

use App\Models\Feature;
use App\Models\FeatureTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class FeatureImport implements ToModel , WithValidation , WithStartRow
{
    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function model(array $row)
    {
        $count = FeatureTranslation::where('feature_name' , '=' , $row[1])->orWhere('feature_name' , '=' , $row[0])->count();

        if (empty($count)) {
            $data = new Feature;
            $data->translateOrNew('en')->feature_name = $row[0];
            $data->translateOrNew('ar')->feature_name = $row[1];
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
