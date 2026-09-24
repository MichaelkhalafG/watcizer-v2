<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * "This equals that" — every category the WooCommerce export uses, and where it lands in OUR tree.
 *
 * ── The table IS the decision, which is why it is a constant and not a config file ───────────
 *
 * The developer reviews this row by row (2026-09-14). A row here is a sentence they can agree or
 * disagree with, and changing one is a code change with a diff and a test — not an edit to a YAML
 * file nobody reads twice.
 *
 * ── What the export actually expresses, and why one column cannot hold it ────────────────────
 *
 * WooCommerce puts everything in one field. A single product reads:
 *
 *     Men, Men > Men Watches, Watches > Quartz, Watches
 *
 * Four entries, and they are four DIFFERENT KINDS of fact:
 *
 *   1. **`Men`** — a GENDER. We have a gender lookup and a `catalog_product_gender` pivot, so a
 *      gender that became a category node would be a permanent lie in the menu: every product in
 *      the shop is for somebody.
 *   2. **`Watches > Quartz`** — a real CATEGORY, and the only kind of entry that belongs in the
 *      tree.
 *   3. **`Leather`, `Satin`** — a MATERIAL, and **also a section customers shop**. The first
 *      draft recorded only the material; the developer's review (2026-09-14) asked for both, and
 *      was right: people do search for "leather bags". So those rows record the material on the
 *      product AND place it under a material node — which is forbidden from ever being the PRIMARY
 *      placement, so a leather handbag stays a BAG in its breadcrumb, its family and its specs.
 *   4. **`Uncategorized`, `Toys`** — nothing usable. It becomes NO category, and the product is
 *      marked as missing one, which is the honest answer and the one the team can act on.
 *
 * So each Woo leaf maps to a TARGET KIND, not merely to a node:
 *
 *   `node`     → a category node, named by the slug path we want it to have
 *   `gender`   → the gender pivot (Men / Women / Unisex)
 *   `material` → the material lookup only (no row uses this alone any more)
 *   `none`     → deliberately dropped; contributes nothing and marks nothing
 *
 * …and a `node` row may additionally carry `material` and `never_primary`; see {@see self::LEAVES}.
 *
 * ── The merged tree ──────────────────────────────────────────────────────────────────────────
 *
 * Our tree has `Watches` (16 children) and `Fashion` (13). The export needs nodes we do not have,
 * and they are listed in {@see self::NEW_NODES} rather than being invented silently inside a
 * mapping row:
 *
 *   • under **Watches**: `quartz` (2 944 products — the single biggest bucket in the file) and
 *     `luxury`;
 *   • under **Fashion**: `shoes`, `slippers`, `bundles`, `accessories`, and a `materials` grouping
 *     node with `leather` and `satin` beneath it;
 *   • under **Fashion → Bags**: the eight bag SHAPES the export distinguishes (shoulder,
 *     crossbody, handbag, tote, backpack, satchel, waist, hobo) — depth 3, which the materialised
 *     `path` supports and the menu renders as a sub-list;
 *   • a new **Electronics** root with six children, on BOTH storefronts, which is also where the
 *     Joyroom price list lands (developer decision 7).
 *
 * Nothing here creates a node on its own. {@see CategoryMerger} does, from this list, and only
 * for nodes a row actually needs.
 */
final class CategoryMap
{
    /** Target kinds a Woo leaf can map to. */
    public const NODE = 'node';

    public const GENDER = 'gender';

    public const MATERIAL = 'material';

    public const NONE = 'none';

    /**
     * The nodes we must CREATE, by slug path, with both names.
     *
     * `parent` is a slug path that must already exist (or appear earlier in this list). Every one
     * of these is an addition to a shop, so each is named the way a customer would look for it.
     *
     * @var array<string, array{parent: string|null, en: string, ar: string, family: string}>
     */
    public const NEW_NODES = [
        // ── under Watches ───────────────────────────────────────────────────────────────────
        'watches/quartz' => ['parent' => 'watches', 'en' => 'Quartz', 'ar' => 'كوارتز', 'family' => 'watch'],
        'watches/luxury' => ['parent' => 'watches', 'en' => 'Luxury', 'ar' => 'ساعات فاخرة', 'family' => 'watch'],

        // ── under Fashion ───────────────────────────────────────────────────────────────────
        'fashion/shoes' => ['parent' => 'fashion', 'en' => 'Shoes', 'ar' => 'أحذية', 'family' => 'fashion'],
        'fashion/slippers' => ['parent' => 'fashion', 'en' => 'Slippers', 'ar' => 'شباشب', 'family' => 'fashion'],
        'fashion/bundles' => ['parent' => 'fashion', 'en' => 'Bundles', 'ar' => 'عروض مجمّعة', 'family' => 'fashion'],
        'fashion/accessories' => ['parent' => 'fashion', 'en' => 'Accessories', 'ar' => 'إكسسوارات', 'family' => 'fashion'],

        /*
         * ── shop by MATERIAL ────────────────────────────────────────────────────────────────
         *
         * A grouping node, then one child per material. The parent exists to say out loud that this
         * axis is a material and not a product type: as bare siblings of Bags and Wallets, "Leather"
         * and "Satin" would read as things rather than as what things are made of.
         *
         * Under FASHION rather than as a root of their own, because that is where the products are:
         * measured on the file, every leather product is a bag, a wallet or a belt, and satin's only
         * co-occurring section is Fashion. A root for two materials would also put a third top-level
         * entry in the menu for ~300 products that already live inside Fashion.
         */
        'fashion/materials' => ['parent' => 'fashion', 'en' => 'Shop by material', 'ar' => 'تسوّق حسب الخامة', 'family' => 'fashion'],
        'fashion/materials/leather' => ['parent' => 'fashion/materials', 'en' => 'Leather', 'ar' => 'جلد', 'family' => 'fashion'],
        'fashion/materials/satin' => ['parent' => 'fashion/materials', 'en' => 'Satin', 'ar' => 'ساتان', 'family' => 'fashion'],

        // ── bag SHAPES, under the existing Bags node (depth 3) ──────────────────────────────
        'fashion/bags/shoulder-bag' => ['parent' => 'fashion/bags', 'en' => 'Shoulder Bags', 'ar' => 'حقائب كتف', 'family' => 'bag'],
        'fashion/bags/crossbody-bag' => ['parent' => 'fashion/bags', 'en' => 'Crossbody Bags', 'ar' => 'حقائب كروس', 'family' => 'bag'],
        'fashion/bags/handbag' => ['parent' => 'fashion/bags', 'en' => 'Handbags', 'ar' => 'حقائب يد', 'family' => 'bag'],
        'fashion/bags/tote-bag' => ['parent' => 'fashion/bags', 'en' => 'Tote Bags', 'ar' => 'حقائب توت', 'family' => 'bag'],
        'fashion/bags/backpack' => ['parent' => 'fashion/bags', 'en' => 'Backpacks', 'ar' => 'حقائب ظهر', 'family' => 'bag'],
        'fashion/bags/satchel' => ['parent' => 'fashion/bags', 'en' => 'Satchels', 'ar' => 'حقائب ساتشيل', 'family' => 'bag'],
        'fashion/bags/waist-bag' => ['parent' => 'fashion/bags', 'en' => 'Waist Bags', 'ar' => 'حقائب خصر', 'family' => 'bag'],
        'fashion/bags/hobo' => ['parent' => 'fashion/bags', 'en' => 'Hobo Bags', 'ar' => 'حقائب هوبو', 'family' => 'bag'],

        // ── the new ELECTRONICS root (developer decision 7) — on BOTH storefronts ────────────
        'electronics' => ['parent' => null, 'en' => 'Electronics', 'ar' => 'إلكترونيات', 'family' => 'electronics'],
        'electronics/chargers' => ['parent' => 'electronics', 'en' => 'Chargers & Cables', 'ar' => 'شواحن وكابلات', 'family' => 'electronics'],
        'electronics/power-banks' => ['parent' => 'electronics', 'en' => 'Power Banks', 'ar' => 'باور بانك', 'family' => 'electronics'],
        'electronics/screen-protectors' => ['parent' => 'electronics', 'en' => 'Screen Protectors', 'ar' => 'واقيات الشاشة', 'family' => 'electronics'],
        'electronics/phone-holders' => ['parent' => 'electronics', 'en' => 'Phone Holders', 'ar' => 'حوامل الهاتف', 'family' => 'electronics'],
        'electronics/audio' => ['parent' => 'electronics', 'en' => 'Earphones & Headphones', 'ar' => 'سماعات', 'family' => 'electronics'],
        'electronics/accessories' => ['parent' => 'electronics', 'en' => 'Accessories & Spare Parts', 'ar' => 'إكسسوارات وقطع غيار', 'family' => 'electronics'],
    ];

    /**
     * Every leaf the export uses → what it means. 60 rows, the count measured in the file.
     *
     * `count` is how many products carried that leaf on 2026-09-14 — it is documentation, not
     * logic, and it is here so a reviewer knows which rows matter. `why` is only written where the
     * mapping is a judgement rather than an obvious synonym.
     *
     * ── Two optional keys, both added by the developer's review of 2026-09-14 ────────────────
     *
     * **`material`** — a leaf can be BOTH a node and a material. "Leather" is a fact about the
     * product (it goes in the material lookup, on the product's own spec block) *and* a section
     * customers shop ("show me leather bags"). Recording only one of the two throws away something
     * real, so a row may do both.
     *
     * **`never_primary`** — a node that must never become the PRIMARY placement. The primary is
     * what the breadcrumb shows and what decides the family and the spec block, and a leather
     * handbag is a BAG: its breadcrumb, family and specs must come from `bags`, never from
     * `leather`. So the material node is an additional placement only. The single exception is a
     * product whose ONLY placement is the material node — it cannot be primary-less, so the node
     * takes the primary and the row is reported (`material_only_primary`) for a human to give it a
     * real type. Measured on this file: 53 Satin rows and 0 Leather rows.
     *
     * @var array<string, array{kind: string, to: string, count: int, why?: string, material?: string, never_primary?: bool}>
     */
    public const LEAVES = [
        // ── genders (3 leaves, 7 596 placements) ─────────────────────────────────────────────
        'Men' => ['kind' => self::GENDER, 'to' => 'Men', 'count' => 4695],
        'Women' => ['kind' => self::GENDER, 'to' => 'Women', 'count' => 2619],
        'Unisex' => ['kind' => self::GENDER, 'to' => 'Unisex', 'count' => 223],

        // ── watches ─────────────────────────────────────────────────────────────────────────
        'Watches' => ['kind' => self::NODE, 'to' => 'watches', 'count' => 4203],
        'Men Watches' => ['kind' => self::NODE, 'to' => 'watches', 'count' => 3323,
            'why' => 'The node is Watches; "Men" is carried by the gender pivot, from the `Men` leaf that always accompanies it.'],
        'Women Watches' => ['kind' => self::NODE, 'to' => 'watches', 'count' => 1524],
        'Unisex Watches' => ['kind' => self::NODE, 'to' => 'watches', 'count' => 8],
        'Quartz' => ['kind' => self::NODE, 'to' => 'watches/quartz', 'count' => 2944, 'why' => 'NEW node — the largest single bucket in the file.'],
        'Automatic' => ['kind' => self::NODE, 'to' => 'watches/automatic', 'count' => 101],
        'Digital' => ['kind' => self::NODE, 'to' => 'watches/digital-watch', 'count' => 7],
        'Smart Watches' => ['kind' => self::NODE, 'to' => 'watches/smart-watch', 'count' => 4],
        'Luxury Watches' => ['kind' => self::NODE, 'to' => 'watches/luxury', 'count' => 63,
            'why' => 'NEW node under Watches rather than the `Legacy category tree` holding node, which exists only to park nodes the legacy data had nowhere else for.'],

        // ── sunglasses ──────────────────────────────────────────────────────────────────────
        'Sun Glasses' => ['kind' => self::NODE, 'to' => 'fashion/sunglasses', 'count' => 1118],
        'Men Sun Glasses' => ['kind' => self::NODE, 'to' => 'fashion/sunglasses', 'count' => 1002],
        'Women Sun Glasses' => ['kind' => self::NODE, 'to' => 'fashion/sunglasses', 'count' => 429],
        'Unisex Sun Glasses' => ['kind' => self::NODE, 'to' => 'fashion/sunglasses', 'count' => 151],
        'womenSun Glasses' => ['kind' => self::NODE, 'to' => 'fashion/sunglasses', 'count' => 1, 'why' => 'A typo in their data; same node.'],

        // ── bags ────────────────────────────────────────────────────────────────────────────
        'Bags & Luggage' => ['kind' => self::NODE, 'to' => 'fashion/bags', 'count' => 383],
        'Women Bags' => ['kind' => self::NODE, 'to' => 'fashion/bags', 'count' => 441],
        'Men Bags' => ['kind' => self::NODE, 'to' => 'fashion/bags', 'count' => 80],
        'Shoulder Bag' => ['kind' => self::NODE, 'to' => 'fashion/bags/shoulder-bag', 'count' => 166],
        'Crossbody Bag' => ['kind' => self::NODE, 'to' => 'fashion/bags/crossbody-bag', 'count' => 125],
        'Handbag' => ['kind' => self::NODE, 'to' => 'fashion/bags/handbag', 'count' => 101],
        'Tote Bag' => ['kind' => self::NODE, 'to' => 'fashion/bags/tote-bag', 'count' => 25],
        'Shopper/Tote' => ['kind' => self::NODE, 'to' => 'fashion/bags/tote-bag', 'count' => 19, 'why' => 'Same shape under two names in their data.'],
        'Backpack' => ['kind' => self::NODE, 'to' => 'fashion/bags/backpack', 'count' => 17],
        'Satchel' => ['kind' => self::NODE, 'to' => 'fashion/bags/satchel', 'count' => 7],
        'Satchel Bag' => ['kind' => self::NODE, 'to' => 'fashion/bags/satchel', 'count' => 4],
        'Waist bag' => ['kind' => self::NODE, 'to' => 'fashion/bags/waist-bag', 'count' => 3],
        'Hobo' => ['kind' => self::NODE, 'to' => 'fashion/bags/hobo', 'count' => 2],

        // ── the rest of fashion ─────────────────────────────────────────────────────────────
        'Fashion' => ['kind' => self::NODE, 'to' => 'fashion', 'count' => 113],
        'Men Wallets' => ['kind' => self::NODE, 'to' => 'fashion/wallets', 'count' => 219],
        'Women Wallets' => ['kind' => self::NODE, 'to' => 'fashion/wallets', 'count' => 31],
        'Men Belts' => ['kind' => self::NODE, 'to' => 'fashion/belts', 'count' => 63],
        'Men Caps' => ['kind' => self::NODE, 'to' => 'fashion/caps', 'count' => 32],
        'Men Perfumes' => ['kind' => self::NODE, 'to' => 'fashion/perfumes', 'count' => 135],
        'Women Perfumes' => ['kind' => self::NODE, 'to' => 'fashion/perfumes', 'count' => 38],
        'Unisex Perfumes' => ['kind' => self::NODE, 'to' => 'fashion/perfumes', 'count' => 8],
        'Men Accessories' => ['kind' => self::NODE, 'to' => 'fashion/accessories', 'count' => 172,
            'why' => 'NEW node. The first draft sent these to the Fashion ROOT, on the argument that our Fashion IS '
                .'the accessories section — corrected by the developer 2026-09-14: a root full of loose products with '
                .'no sub-section is worse than one repeated word. Measured: 133 of these 204 in-scope products have no '
                .'other type leaf at all, so without this node they would have had no section to sit in.'],
        'Women Accessories' => ['kind' => self::NODE, 'to' => 'fashion/accessories', 'count' => 70],
        'Men Shoes' => ['kind' => self::NODE, 'to' => 'fashion/shoes', 'count' => 46, 'why' => 'NEW node.'],
        'Women Shoes' => ['kind' => self::NODE, 'to' => 'fashion/shoes', 'count' => 11],
        'Men Slippers' => ['kind' => self::NODE, 'to' => 'fashion/slippers', 'count' => 9, 'why' => 'NEW node.'],
        'Women Slippers' => ['kind' => self::NODE, 'to' => 'fashion/slippers', 'count' => 9],
        'Bundles' => ['kind' => self::NODE, 'to' => 'fashion/bundles', 'count' => 45, 'why' => 'NEW node. A bundle is a thing they sell, so it is a section rather than a tag.'],
        'Men Bundles' => ['kind' => self::NODE, 'to' => 'fashion/bundles', 'count' => 16],
        'Men Swimsuit' => ['kind' => self::NODE, 'to' => 'fashion', 'count' => 1,
            'why' => 'One product. A whole swimwear section for one item is clutter; it sits in Fashion until there are enough to name.'],
        'Men Socks' => ['kind' => self::NODE, 'to' => 'fashion', 'count' => 1, 'why' => 'One product, as above.'],

        // ── electronics (the new root — and where Joyroom lands) ─────────────────────────────
        'Electronics' => ['kind' => self::NODE, 'to' => 'electronics', 'count' => 115],
        'Charger' => ['kind' => self::NODE, 'to' => 'electronics/chargers', 'count' => 43],
        'Screen Protector' => ['kind' => self::NODE, 'to' => 'electronics/screen-protectors', 'count' => 22],
        'Phone Holder' => ['kind' => self::NODE, 'to' => 'electronics/phone-holders', 'count' => 11],
        'Power bank' => ['kind' => self::NODE, 'to' => 'electronics/power-banks', 'count' => 8],
        'Earphones' => ['kind' => self::NODE, 'to' => 'electronics/audio', 'count' => 6],
        'Headphones' => ['kind' => self::NODE, 'to' => 'electronics/audio', 'count' => 1],
        'Accessories and Spare Parts' => ['kind' => self::NODE, 'to' => 'electronics/accessories', 'count' => 58],

        // ── materials, which were never categories ──────────────────────────────────────────
        'Leather' => ['kind' => self::NODE, 'to' => 'fashion/materials/leather', 'material' => 'Leather',
            'never_primary' => true, 'count' => 215,
            'why' => 'BOTH, by the developer\'s decision 2026-09-14: the material is recorded on the product AND the '
                .'product is placed in a browsable Leather section, because customers really do shop "leather". The node '
                .'can never be the PRIMARY placement — a leather handbag is a bag, and its breadcrumb, family and spec '
                .'block come from `bags`. Measured: every one of the 166 in-scope leather products also carries a type '
                .'leaf (bags 69, wallets 62, belts 25…), so none of them needs the exception.'],
        'Satin' => ['kind' => self::NODE, 'to' => 'fashion/materials/satin', 'material' => 'Satin',
            'never_primary' => true, 'count' => 138,
            'why' => 'As Leather. The material does not exist in our lookup yet and is created. **53 of its 136 '
                .'in-scope products carry NO type leaf at all** — for those the material node becomes the primary, '
                .'because a placed product cannot be primary-less, and every one is named in the import report under '
                .'`material_only_primary` so the team can give it a real section.'],

        // ── nothing usable ──────────────────────────────────────────────────────────────────
        'Uncategorized' => ['kind' => self::NONE, 'to' => '', 'count' => 75,
            'why' => 'Their own "no answer". It becomes no category here, and the product is MARKED as missing one — which is the thing the team can act on.'],
        'Toys' => ['kind' => self::NONE, 'to' => '', 'count' => 1,
            'why' => 'One product, and a Toys section in a watch and fashion shop is a mistake in their data rather than a section of ours.'],
    ];

    /**
     * Woo's gender leaves → the id of our gender lookup row, by English name.
     *
     * @var array<string, string>
     */
    public const GENDERS = ['Men' => 'Men', 'Women' => 'Women', 'Unisex' => 'Unisex'];

    /**
     * Materials the map needs that the lookup may not have. Created with both names, like a node.
     *
     * @var array<string, array{en: string, ar: string}>
     */
    public const NEW_MATERIALS = [
        'Satin' => ['en' => 'Satin', 'ar' => 'ساتان'],
    ];

    /**
     * May this node path be a product's PRIMARY placement?
     *
     * False only for the material nodes, and the reason is the whole point of them: the primary is
     * what the breadcrumb shows and what `FamilyForCategory` reads, so a leather handbag whose
     * primary was `leather` would render as a leather-family product with a material for a
     * breadcrumb. Derived from the map so the two can never disagree.
     */
    public static function mayBePrimary(string $path): bool
    {
        foreach (self::LEAVES as $mapping) {
            if ($mapping['to'] === $path && ($mapping['never_primary'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    /** @return array{kind: string, to: string, count: int, why?: string, material?: string, never_primary?: bool}|null */
    public static function leaf(string $name): ?array
    {
        $key = trim($name);

        return self::LEAVES[$key] ?? null;
    }

    /**
     * The leaf of one Woo category entry: `Watches > Quartz` → `Quartz`.
     *
     * The PATH is thrown away on purpose. Their tree and ours disagree about what the parent of a
     * thing is (`Men > Men Watches` vs `Watches`), and honouring their parent would import their
     * disagreement. The leaf is the only part that names a thing.
     */
    public static function leafOf(string $entry): string
    {
        $parts = explode('>', $entry);

        return trim((string) end($parts));
    }

    /**
     * Every node path this map can produce, in creation order (parents first).
     *
     * @return list<string>
     */
    public static function nodePaths(): array
    {
        return array_keys(self::NEW_NODES);
    }
}
