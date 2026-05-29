<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProductExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            'Product Details' => new ProductDetailsSheet(),
            'All Create a product' => new AllDataSheets(),
        ];
    }
}
