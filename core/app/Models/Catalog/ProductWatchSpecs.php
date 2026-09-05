<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `catalog_product_watch_specs` — 1:1 watch-only attributes keyed by product_id (CASCADE).
 */
class ProductWatchSpecs extends Model
{
    protected $table = 'catalog_product_watch_specs';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'case_size', 'case_size_unit_id', 'case_shape_id', 'case_material_id', 'glass_material_id',
        'case_thickness', 'case_thickness_unit_id',
        'band_material_id', 'band_closure_id', 'band_length', 'band_length_unit_id', 'band_width', 'band_width_unit_id',
        'dial_display_type_id', 'movement_type_id',
        'water_resistance', 'water_resistance_unit_id',
        'height', 'height_unit_id', 'width', 'width_unit_id', 'length', 'length_unit_id',
        'interchangeable_dial', 'interchangeable_strap', 'watch_box',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'case_size' => 'decimal:2',
            'case_thickness' => 'decimal:2',
            'band_length' => 'decimal:2',
            'band_width' => 'decimal:2',
            'height' => 'decimal:2',
            'width' => 'decimal:2',
            'length' => 'decimal:2',
            'water_resistance' => 'integer',
            'interchangeable_dial' => 'boolean',
            'interchangeable_strap' => 'boolean',
            'watch_box' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Shape, $this> */
    public function caseShape(): BelongsTo
    {
        return $this->belongsTo(Shape::class, 'case_shape_id');
    }

    /** @return BelongsTo<Material, $this> */
    public function caseMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'case_material_id');
    }

    /** @return BelongsTo<Material, $this> */
    public function glassMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'glass_material_id');
    }

    /** @return BelongsTo<Material, $this> */
    public function bandMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'band_material_id');
    }

    /** @return BelongsTo<ClosureType, $this> */
    public function bandClosure(): BelongsTo
    {
        return $this->belongsTo(ClosureType::class, 'band_closure_id');
    }

    /** @return BelongsTo<DisplayType, $this> */
    public function dialDisplayType(): BelongsTo
    {
        return $this->belongsTo(DisplayType::class, 'dial_display_type_id');
    }

    /** @return BelongsTo<MovementType, $this> */
    public function movementType(): BelongsTo
    {
        return $this->belongsTo(MovementType::class, 'movement_type_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function caseSizeUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'case_size_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function caseThicknessUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'case_thickness_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function bandLengthUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'band_length_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function bandWidthUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'band_width_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function waterResistanceUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'water_resistance_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function heightUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'height_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function widthUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'width_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function lengthUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'length_unit_id');
    }
}
