<?php

namespace App\Transform;

use RuntimeException;

/**
 * Find-or-create for storefront_categories nodes keyed by their legacy origin
 * (storefront_id, legacy_source, legacy_id, legacy_parent_id) — the natural key of
 * steps 15/16/17. Ids are database-assigned, so this is a lookup + insert/update
 * rather than an upsert; the materialised `path` is written once the id is known.
 */
final class CategoryNodes
{
    /** @var list<string> */
    private const COLUMNS = [
        'storefront_id', 'parent_id', 'depth', 'path', 'slug', 'image_path', 'icon', 'is_active', 'show_in_menu',
        'sort_order', 'legacy_source', 'legacy_id', 'legacy_parent_id', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    private const UPDATE = ['parent_id', 'depth', 'path', 'slug', 'image_path', 'is_active', 'show_in_menu', 'sort_order', 'updated_at'];

    /** @var array<string, int> slug => id, per run, for collision checks */
    private array $slugs = [];

    public function __construct(private readonly TransformContext $ctx) {}

    /** Load the storefront's existing slugs so collision handling sees them. */
    public function prime(): void
    {
        $this->slugs = [];
        $rows = $this->ctx->db->table('storefront_categories')->select(['id', 'slug'])->where('storefront_id', $this->ctx->storefrontId)->get();
        foreach ($rows as $row) {
            $this->slugs[Row::str($row, 'slug')] = Row::int($row, 'id');
        }
    }

    public function find(string $source, int $legacyId, ?int $legacyParentId): ?int
    {
        $q = $this->ctx->db->table('storefront_categories')
            ->where('storefront_id', $this->ctx->storefrontId)
            ->where('legacy_source', $source)
            ->where('legacy_id', $legacyId);
        $q = $legacyParentId === null ? $q->whereNull('legacy_parent_id') : $q->where('legacy_parent_id', $legacyParentId);
        $row = $q->select(['id'])->first();

        return $row === null ? null : Row::int($row, 'id');
    }

    /**
     * @param  array{source: string, legacy_id: int, legacy_parent_id: int|null, parent_id: int|null, slug: string, slug_fallbacks: list<string>, image_path: string|null, is_active: int, show_in_menu: int, sort_order: int, created_at: string|null, updated_at: string|null}  $node
     * @param  array{en: string, ar: string, description_en?: string|null, description_ar?: string|null}  $names
     * @return array{id: int, inserted: bool, updated: bool, slug: string}
     */
    public function ensure(array $node, array $names, StepResult $result): array
    {
        $existingId = $this->find($node['source'], $node['legacy_id'], $node['legacy_parent_id']);

        $slug = $this->resolveSlug($node['slug'], $node['slug_fallbacks'], $existingId, $node['legacy_id']);

        $parentId = $node['parent_id'];
        $depth = 1;
        $parentPath = '/';
        if ($parentId !== null) {
            $parent = $this->ctx->db->table('storefront_categories')->select(['depth', 'path'])->where('id', $parentId)->first();
            if ($parent === null) {
                throw new RuntimeException("storefront_categories parent $parentId not found for {$node['source']}#{$node['legacy_id']}.");
            }
            $depth = Row::int($parent, 'depth') + 1;
            $parentPath = Row::str($parent, 'path');
        }

        $values = [
            'storefront_id' => $this->ctx->storefrontId,
            'parent_id' => $parentId,
            'depth' => $depth,
            'path' => '',
            'slug' => $slug,
            'image_path' => $node['image_path'],
            'icon' => null,
            'is_active' => $node['is_active'],
            'show_in_menu' => $node['show_in_menu'],
            'sort_order' => $node['sort_order'],
            'legacy_source' => $node['source'],
            'legacy_id' => $node['legacy_id'],
            'legacy_parent_id' => $node['legacy_parent_id'],
            'created_at' => $node['created_at'],
            'updated_at' => $node['updated_at'],
        ];

        $inserted = false;
        $updated = false;
        if ($existingId === null) {
            $id = $this->ctx->writer->insertGetId('storefront_categories', self::COLUMNS, $values);
            $values['path'] = $parentPath.$id.'/';
            $this->ctx->writer->updateById('storefront_categories', $id, ['path'], ['path' => $values['path']]);
            $inserted = true;
        } else {
            $id = $existingId;
            $values['path'] = $parentPath.$id.'/';
            $current = $this->ctx->db->table('storefront_categories')->select(self::UPDATE)->where('id', $id)->first();
            if ($current === null) {
                throw new RuntimeException("storefront_categories#$id vanished mid-run.");
            }
            $changes = [];
            foreach (self::UPDATE as $col) {
                $new = $values[$col];
                $old = $current->{$col};
                if ((string) (is_scalar($old) ? $old : '') !== (string) (is_scalar($new) ? $new : '') || ($old === null) !== ($new === null)) {
                    $changes[$col] = $new;
                }
            }
            if ($changes !== []) {
                $this->ctx->writer->updateById('storefront_categories', $id, array_keys($changes), $changes);
                $updated = true;
            }
        }
        $this->slugs[$slug] = $id;

        $translations = [
            ['storefront_category_id' => $id, 'locale' => 'en', 'name' => $names['en'], 'description' => self::nullIfBlank($names['description_en'] ?? null), 'meta_title' => null, 'meta_description' => null],
            ['storefront_category_id' => $id, 'locale' => 'ar', 'name' => $names['ar'], 'description' => self::nullIfBlank($names['description_ar'] ?? null), 'meta_title' => null, 'meta_description' => null],
        ];
        $result->writes->add($this->ctx->writer->upsert('storefront_category_translations', ['storefront_category_id', 'locale', 'name', 'description', 'meta_title', 'meta_description'], ['storefront_category_id', 'locale'], ['name', 'description'], $translations));

        if ($inserted) {
            $result->writes->inserted++;
        } elseif ($updated) {
            $result->writes->updated++;
        } else {
            $result->writes->unchanged++;
        }

        return ['id' => $id, 'inserted' => $inserted, 'updated' => $updated, 'slug' => $slug];
    }

    /** @param  list<string>  $fallbacks */
    private function resolveSlug(string $slug, array $fallbacks, ?int $selfId, int $legacyId): string
    {
        $candidates = array_merge([$slug], $fallbacks, [$slug.'-'.$legacyId]);
        foreach ($candidates as $candidate) {
            $candidate = mb_substr($candidate, 0, 191);
            $owner = $this->slugs[$candidate] ?? null;
            if ($owner === null || $owner === $selfId) {
                return $candidate;
            }
        }
        throw new RuntimeException("Could not find a free slug for category [$slug] (legacy id $legacyId).");
    }

    private static function nullIfBlank(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : $v;
    }
}
