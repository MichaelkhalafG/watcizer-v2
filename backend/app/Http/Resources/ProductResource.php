<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request)
    {
        $data = parent::toArray($request);
        
        // إضافة الـ 3-level categories مع الترجمات
        $data['main_category'] = $this->whenLoaded('mainCategory', function() {
            return [
                'id' => $this->mainCategory->id,
                'slug' => $this->mainCategory->slug,
                'name' => $this->mainCategory->name,
                'translations' => $this->mainCategory->translations,
            ];
        });
        
        $data['sub_category'] = $this->whenLoaded('subCategory', function() {
            return [
                'id' => $this->subCategory->id,
                'slug' => $this->subCategory->slug,
                'name' => $this->subCategory->name,
                'translations' => $this->subCategory->translations,
            ];
        });
        
        $data['product_type'] = $this->whenLoaded('productType', function() {
            return [
                'id' => $this->productType->id,
                'slug' => $this->productType->slug,
                'name' => $this->productType->name,
                'translations' => $this->productType->translations,
            ];
        });
        
        // إضافة الـ images URLs مباشرة
        $data['image_url'] = $this->image 
            ? url('Uploads_Images/Product/' . $this->image) 
            : null;
            
        $data['gallery_images'] = $this->whenLoaded('product_image', function() {
            return $this->product_image->map(function($image) {
                return [
                    'id' => $image->id,
                    'url' => url('Uploads_Images/Product_image/' . $image->image),
                    'is_cover' => $image->is_cover,
                    'sort' => $image->sort,
                ];
            });
        });
        
        return $data;
    }
}