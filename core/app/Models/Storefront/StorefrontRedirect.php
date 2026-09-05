<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `storefront_redirects` — WooCommerce 301s, slug-change history and manual redirects.
 * `from_hash` = sha1(lower(from_path)) keeps the unique index short.
 */
class StorefrontRedirect extends Model
{
    protected $table = 'storefront_redirects';

    /** @var list<string> */
    protected $fillable = ['storefront_id', 'from_hash', 'from_path', 'to_path', 'status', 'source', 'hits', 'last_hit_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => 'integer', 'hits' => 'integer', 'last_hit_at' => 'datetime'];
    }

    public static function hashPath(string $path): string
    {
        return sha1(mb_strtolower(trim($path)));
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }
}
