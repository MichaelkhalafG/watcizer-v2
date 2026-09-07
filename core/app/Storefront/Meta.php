<?php

namespace App\Storefront;

use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/v2/{storefront}/meta` (CLEAN_CORE_STUDY §3.5.2 native column): storefront public
 * settings, brands, grades, the filter lookups, the visible menu tree and the scheduled banners.
 * Cached per version (`sf:{id}:meta:v{n}`).
 */
final class Meta
{
    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCache $cache,
        private readonly Lookups $lookups,
        private readonly CategoryTree $tree,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        /** @var array<string, mixed> $meta */
        $meta = $this->cache->remember($this->ctx->id(), 'meta', '', config()->integer('storefront.ttl.meta'), fn () => $this->assemble());
        $meta['locale'] = $this->ctx->locale;

        return $meta;
    }

    /** @return array<string, mixed> */
    private function assemble(): array
    {
        $sf = $this->ctx->storefront;
        $settings = $sf->getAttribute('settings');
        $public = is_array($settings) ? ($settings['public'] ?? []) : [];

        return [
            'storefront' => [
                'code' => $sf->code,
                'name' => $sf->name,
                'domain' => $sf->domain,
                'locales' => $this->ctx->locales(),
                'default_locale' => $this->ctx->defaultLocale(),
                'currency' => $sf->currency,
                'settings' => is_array($public) ? $public : [],
            ],
            'brands' => array_values(array_filter($this->lookups->list('brands'), fn (array $b) => ($b['is_active'] ?? true) === true)),
            'grades' => $this->lookups->list('grades'),
            'filters' => [
                'colors' => $this->lookups->list('colors'),
                'materials' => $this->lookups->list('materials'),
                'shapes' => $this->lookups->list('shapes'),
                'display_types' => $this->lookups->list('display_types'),
                'movements' => $this->lookups->list('movements'),
                'genders' => $this->lookups->list('genders'),
            ],
            'tree' => $this->tree->nested(),
            'banners' => $this->banners(),
        ];
    }

    /**
     * Active, in-window banners of the storefront.
     *
     * @return list<array<string, mixed>>
     */
    private function banners(): array
    {
        $now = now();
        $rows = DB::table('storefront_banners')
            ->select(['id', 'placement', 'image_path', 'type_show', 'link_url', 'product_id', 'storefront_category_id', 'sort_order', 'starts_at', 'ends_at'])
            ->where('storefront_id', $this->ctx->id())
            ->where('is_active', 1)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('placement')->orderBy('sort_order')->orderBy('id')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $node = $this->tree->node(Row::nint($r, 'storefront_category_id') ?? 0);
            $out[] = [
                'id' => Row::int($r, 'id'),
                'placement' => Row::str($r, 'placement'),
                'image' => ImageUrl::object(Row::str($r, 'image_path'), null, null, null, null),
                'type_show' => Row::nstr($r, 'type_show'),
                'link_url' => Row::nstr($r, 'link_url'),
                'product_id' => Row::nint($r, 'product_id'),
                'category' => $node === null ? null : CategoryTree::ref($node),
                'starts_at' => ProductCards::ts(Row::nstr($r, 'starts_at')),
                'ends_at' => ProductCards::ts(Row::nstr($r, 'ends_at')),
            ];
        }

        return $out;
    }
}
