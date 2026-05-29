<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Grade;
use App\Models\Product;
use App\Models\SubType;
use App\Models\SizeType;
use App\Models\ClosureType;
use App\Models\DisplayType;
use App\Models\CategoryType;
use App\Models\Feature;
use App\Models\Gender;
use App\Models\Material;
use App\Models\MovementType;
use App\Models\Shape;
use Maatwebsite\Excel\Concerns\ToModel;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ProductImport implements ToModel , WithValidation , WithStartRow , WithMultipleSheets
{
    private $filePath;

    private $category_type, $sub_type, $brand, $gender, $feature, $grade, $color, $closure_type, $display_type, $size_type, $shape, $material, $movement_type;


    public function sheets(): array
    {
        return [
            0 => $this,
        ];
    }

    public function startRow(): int
    {
        return 2; // Skip the header row (row 1)
    }

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
        $this->category_type = CategoryType::withTranslation('en')->get()->pluck('id', 'translations.0.category_type_name');
        $this->sub_type = SubType::withTranslation('en')->get()->pluck('id', 'translations.0.sub_type_name');
        $this->brand = Brand::withTranslation('en')->get()->pluck('id', 'translations.0.brand_name');
        $this->gender = Gender::withTranslation('en')->get()->pluck('id', 'translations.0.gender_name');
        $this->feature = Feature::withTranslation('en')->get()->pluck('id', 'translations.0.feature_name');
        $this->grade = Grade::withTranslation('en')->get()->pluck('id', 'translations.0.grade_name');
        $this->color = Color::all()->pluck('id', 'color_value');
        $this->closure_type = ClosureType::withTranslation('en')->get()->pluck('id', 'translations.0.closure_type_name');
        $this->display_type = DisplayType::withTranslation('en')->get()->pluck('id', 'translations.0.display_type_name');
        $this->size_type = SizeType::withTranslation('en')->get()->pluck('id', 'translations.0.size_type_name');
        $this->shape = Shape::withTranslation('en')->get()->pluck('id', 'translations.0.shape_name');
        $this->material = Material::withTranslation('en')->get()->pluck('id', 'translations.0.material_name');
        $this->movement_type = MovementType::withTranslation('en')->get()->pluck('id', 'translations.0.movement_type_name');
    }

    public function model(array $row)
    {
        $path        = $this->filePath;
        $reader      = new Xlsx();
        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $drawings    = $sheet->getDrawingCollection();

        // Find the drawing associated with the current row
        foreach ($drawings as $drawing) {

            // Associate the image with the current row
            if ($drawing->getCoordinates() == $row[0]) {
                $drawing_path = $drawing->getPath();
                $drawing->setResizeProportional(true);

                $image_name = uniqid() . '_' . time() . '.' . 'webp';

                $product = Product::where('wa_code', '=', $row[6])->first();

                if (!$product) {
                    $product = new Product;

                    $contents = file_get_contents($drawing_path);
                    $manager = new ImageManager(new Driver());
                    $img     = $manager->read($contents);
                    $img->toWebp()->save(public_path('/Uploads_Images/Product/' . $image_name));

                    $product->image = $image_name;

                } else {
                    // Check if the image is the same as the old one
                    $old_img_path = public_path("/Uploads_Images/Product/" . $product->image);
                    if (!file_exists($old_img_path) || hash_file('sha256', $drawing_path) !== hash_file('sha256', $old_img_path)) {
                        // Delete the old image if it exists
                        if (file_exists($old_img_path)) {
                            unlink($old_img_path);
                        }

                        // Save the new image
                        $contents = file_get_contents($drawing_path);
                        $manager = new ImageManager(new Driver());
                        $img     = $manager->read($contents);
                        $img->toWebp()->save(base_path('public/Uploads_Images/Product/' . $image_name));

                        // Update image name
                        $product->image = $image_name;
                    } else {
                        // Skip saving the new image if unchanged
                        unset($product['image']);
                    }
                }

                $product->translateOrNew('ar')->product_title = $row[1];
                $product->translateOrNew('en')->product_title = $row[2];
                $product->sub_type_id               = $this->sub_type->get($row[3]);
                $product->category_type_id          = $this->category_type->get($row[4]);
                $product->brand_id                  = $this->brand->get($row[5]);
                $product->wa_code                   = $row[6];
                $product->purchase_price            = $row[7];
                $product->selling_price             = $row[8];
                $product->sale_price_after_discount = $row[9];
                $product->percentage_discount       = $row[10];
                $product->stock                     = $row[11];
                $product->active                    = $this->parseBoolean($row[12]);
                $product->translateOrNew('ar')->short_description = $row[13];
                $product->translateOrNew('en')->short_description = $row[14];
                $product->translateOrNew('ar')->long_description  = $row[15];
                $product->translateOrNew('en')->long_description  = $row[16];
                $product->grade_id                      = $this->grade->get($row[19]);
                $product->band_closure_id               = $this->closure_type->get($row[22]);
                $product->dial_display_type_id          = $this->display_type->get($row[23]);
                $product->case_size                     = $row[24];
                $product->case_size_type_id             = $this->size_type->get($row[25]);
                $product->case_shape_id                 = $this->shape->get($row[26]);
                $product->band_material_id              = $this->material->get($row[27]);
                $product->watch_movement_id             = $this->movement_type->get($row[28]);
                $product->band_length                   = $row[29];
                $product->band_size_type_id             = $this->size_type->get($row[30]);
                $product->water_resistance              = $row[31];
                $product->water_resistance_size_type_id = $this->size_type->get($row[32]);
                $product->band_width                    = $row[33];
                $product->band_width_size_type_id       = $this->size_type->get($row[34]);
                $product->case_thickness                = $row[35];
                $product->case_thickness_size_type_id   = $this->size_type->get($row[36]);
                $product->dial_case_material_id         = $this->material->get($row[37]);
                $product->dial_glass_material_id        = $this->material->get($row[38]);
                $product->watch_height                  = $row[39];
                $product->watch_height_size_type_id     = $this->size_type->get($row[40]);
                $product->watch_width                   = $row[41];
                $product->watch_width_size_type_id      = $this->size_type->get($row[42]);
                $product->translateOrNew('ar')->model_name  = $row[43];
                $product->translateOrNew('en')->model_name  = $row[44];
                $product->model_number                   = $row[45];
                $product->watch_length                   = $row[46];
                $product->watch_length_size_type_id      = $this->size_type->get($row[47]);
                $product->warranty_years                 = $row[48];
                $product->interchangeable_dial           = $this->parseBoolean($row[49]);
                $product->interchangeable_strap          = $this->parseBoolean($row[50]);
                $product->watch_box                      = $this->parseBoolean($row[51]);
                $product->sku_unique                     = $row[52];
                $product->translateOrNew('ar')->country  = $row[53];
                $product->translateOrNew('en')->country  = $row[54];
                $product->translateOrNew('ar')->stone    = $row[55];
                $product->translateOrNew('en')->stone    = $row[56];
                $product->market_stock                           = $row[57];
                $product->search_keywords                        = $row[58];
                $product->created_by                             = auth()->user()->id;

                $product->save();
                $genders    = $this->parseArray($this->gender, $row[17]);
                $features   = $this->parseArray($this->feature, $row[18]);
                $bandColors = $this->parseArray($this->color, $row[21]);
                $dialColors = $this->parseArray($this->color, $row[20]);
                $product->gender()->sync($genders);
                $product->feature()->sync($features);
                $product->bandColor()->sync($bandColors);
                $product->dialColor()->sync($dialColors);


            }
        }
    }

    public function parseBoolean($value)
    {
        return (strtolower($value) === 'yes') ? 1 : 0;
    }

    public function parseArray($data, $rowValue)
    {
        $result = [];
        $array = preg_split('/\s*[-,]\s*/', $rowValue);
        foreach ($array as $key) {
            if (isset($data[$key])) {
                $result[] = $data[$key];
            }
        }
        return $result;
    }

    public function rules(): array
    {
        return [
            '0'  => 'required',
            '1'  => 'required|string|min:2|max:255',
            '2'  => 'required|string|min:2|max:255',
            '3'  => 'required|string',
            '4'  => 'required|string',
            '5'  => 'required|string',
            '6'  => 'required|min:2|max:255',
            '7'  => 'required|numeric|min:0',
            '8'  => 'required|numeric|min:0',
            '9'  => 'nullable|numeric|min:0',
            '10' => 'required|numeric|min:0',
            '11' => 'required|numeric|min:0',
            '12' => 'required|string',
            '13' => 'required|string',
            '14' => 'required|string',
            '15' => 'required|string',
            '16' => 'required|string',
            '17' => 'nullable|string',
            '18' => 'nullable|string',
            '19' => 'nullable|string',
            '20' => 'nullable|string',
            '21' => 'nullable|string',
            '22' => 'nullable|string',
            '23' => 'nullable|string',
            '24' => 'nullable|numeric',
            '25' => 'nullable|string',
            '26' => 'nullable|string',
            '27' => 'nullable|string',
            '28' => 'nullable|string',
            '29' => 'nullable|numeric',
            '30' => 'nullable|string',
            '31' => 'nullable|numeric',
            '32' => 'nullable|string',
            '33' => 'nullable|numeric',
            '34' => 'nullable|string',
            '35' => 'nullable|numeric',
            '36' => 'nullable|string',
            '37' => 'nullable|string',
            '38' => 'nullable|string',
            '39' => 'nullable|numeric',
            '40' => 'nullable|string',
            '41' => 'nullable|numeric',
            '42' => 'nullable|string',
            '43' => 'nullable|string',
            '44' => 'nullable|string',
            '45' => 'nullable|string',
            '46' => 'nullable|numeric',
            '47' => 'nullable|string',
            '48' => 'nullable',
            '49' => 'nullable|string',
            '50' => 'nullable|string',
            '51' => 'nullable|string',
            '52' => 'nullable|string',
            '53' => 'nullable|string',
            '54' => 'nullable|string',
            '56' => 'nullable|string',
            '57' => 'nullable|numeric|min:0',
            '58' => 'nullable|string',
        ];
    }
}
