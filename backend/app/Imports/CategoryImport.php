<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\CategoryTranslation;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class CategoryImport implements ToModel , WithValidation , WithStartRow
{
    private $filePath;

    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    public function model(array $row)
    {
        // Check if the category already exists
        $categoryExists = CategoryTranslation::where('category_name', '=', $row[1])
            ->orWhere('category_name', '=', $row[0])
            ->exists();

        if (!$categoryExists) {
            $path        = $this->filePath;
            $reader      = new Xlsx();
            $spreadsheet = $reader->load($path);
            $sheet       = $spreadsheet->getActiveSheet();
            $drawings    = $sheet->getDrawingCollection();

            // Find the drawing associated with the current row
            foreach ($drawings as $drawing) {
                // Associate the image with the current row
                if ($drawing->getCoordinates() == $row[3]) { // Example: Ensure image matches row coordinates
                    $drawing_path = $drawing->getPath();
                    $drawing->setResizeProportional(true);

                    $image_name = uniqid() . '_' . time() . '.' . 'webp';

                    $contents = file_get_contents($drawing_path);
                    $manager = new ImageManager(new Driver());
                    $img     = $manager->read($contents);
                    $img->toWebp()->save(public_path('/Uploads_Images/Category/' . $image_name));


                    // Save the row and image
                    $data = new Category;
                    $data->translateOrNew('en')->category_name = $row[0];
                    $data->translateOrNew('ar')->category_name = $row[1];
                    $data->color_value    = $row[2];
                    $data->category_image = $image_name; // Save image name
                    $data->save();
                }
            }
        }
    }

    public function rules(): array
    {
        return [
            '0' => ['required', 'min:2', 'max:255'],
            '1' => ['required', 'min:2', 'max:255'],
            '2' => ['required', 'min:2', 'max:255', 'hex_color',],
            '3' => ['required'],
        ];
    }

}
