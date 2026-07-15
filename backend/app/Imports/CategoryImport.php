<?php

namespace App\Imports;

use App\Models\Category;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Category import aligned with the restructured categories schema:
 *   col 0 = Name (EN)  → translation `name`
 *   col 1 = Name (AR)  → translation `name`
 *   col 2 = Parent slug (optional) → resolves parent_id / level
 *   col 3 = Slug (optional; auto-generated from EN name when blank)
 *   col 4 = Active (0/1)
 *   col 5 = Image cell coordinate (e.g. F2) — the embedded drawing
 */
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
        $nameEn = trim((string) ($row[0] ?? ''));
        $nameAr = trim((string) ($row[1] ?? ''));
        $slug   = trim((string) ($row[3] ?? '')) ?: Str::slug($nameEn);

        // Skip if a category with this slug already exists.
        if (Category::where('slug', $slug)->exists()) {
            return null;
        }

        // Resolve parent from its slug (optional).
        $parent   = null;
        $parentSlug = trim((string) ($row[2] ?? ''));
        if ($parentSlug !== '') {
            $parent = Category::where('slug', $parentSlug)->first();
        }

        $category = new Category;
        $category->translateOrNew('en')->name = $nameEn;
        $category->translateOrNew('ar')->name = $nameAr;
        $category->slug       = $slug;
        $category->parent_id  = $parent?->id;
        $category->level      = $parent ? $parent->level + 1 : 1;
        $category->is_active  = (bool) ($row[4] ?? true);
        $category->sort_order = 0;

        // Embedded image (optional).
        $imageCoord = trim((string) ($row[5] ?? ''));
        if ($imageCoord !== '') {
            $reader      = new Xlsx();
            $spreadsheet = $reader->load($this->filePath);
            $sheet       = $spreadsheet->getActiveSheet();
            foreach ($sheet->getDrawingCollection() as $drawing) {
                if ($drawing->getCoordinates() == $imageCoord) {
                    $drawing->setResizeProportional(true);
                    $image_name = uniqid() . '_' . time() . '.webp';
                    $contents   = file_get_contents($drawing->getPath());
                    $manager    = new ImageManager(new Driver());
                    $manager->read($contents)->toWebp()
                        ->save(public_path('/Uploads_Images/Category/' . $image_name));
                    $category->image = $image_name;
                }
            }
        }

        $category->save();

        return null;
    }

    public function rules(): array
    {
        return [
            '0' => ['required', 'min:2', 'max:255'],
            '1' => ['required', 'min:2', 'max:255'],
        ];
    }
}
