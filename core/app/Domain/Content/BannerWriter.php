<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Content\BannerImage;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one door for home-page banners (wave 4D).
 *
 * ── Why this table needs a writer at all ─────────────────────────────────────────────────────
 *
 * A banner is the most visible row in the shop: it is the first thing a customer sees, it is one
 * image, and it links somewhere. The ways to get it wrong are few and expensive — a link that goes
 * nowhere, a window that closes without anyone noticing, an image nobody uploaded — so every one of
 * them is a refusal here rather than a validation rule on a screen a script can skip.
 *
 * ── `placement` stays OPEN in the column and CLOSED in the code ──────────────────────────────
 *
 * The legacy app had three placements (`banner_homes`, `banner_sides`, `banner_bottoms`) and the
 * clean table carries one `placement` column for all of them. The developer's decision (2026-09-14)
 * is HOME ONLY, and this writer enforces it with {@see self::PLACEMENTS} rather than a migration
 * that turns the column into an enum:
 *
 *   • the storefront reads `where placement = 'home'`, so a value it does not know renders nothing
 *     — the column being open cannot leak a banner onto a page;
 *   • a whitelist in the writer refuses with a sentence an operator can act on, where a column
 *     constraint gives them a 1265 from the driver;
 *   • and the day a side rail is wanted, it is one entry here and a storefront query, not a
 *     migration on a table two applications share.
 *
 * ── The link is ONE of three things, and the writer says which ───────────────────────────────
 *
 * A banner points at a product, a category, or a URL. Storing two of them would make the storefront
 * choose, so exactly one is kept and the others are nulled — the same "one decision, stored once"
 * rule the promotion rewards follow.
 */
final class BannerWriter
{
    /** Every placement this application serves. HOME only, by decision. */
    public const PLACEMENTS = ['home'];

    /** What a banner can point at. */
    public const TARGETS = ['product', 'category', 'url', 'none'];

    /**
     * Which device shows a banner (F1, the developer's decision 2026-10-20). ONE column, three values, `both` the
     * default — one banner is one row, and "the same artwork tuned twice" is two rows.
     *
     * The storefront FILTERS ON THE CLIENT, never here: the CDN keeps one copy per URL and ignores `Vary`, so an
     * endpoint that narrowed by user-agent would serve a desktop visitor a cached phone answer (V2-CDN-PRIVATE,
     * 2026-10-19). Every active banner goes out with its `device`; the page decides.
     */
    public const DEVICES = ['both', 'desktop', 'mobile'];

    /** A stored `device` as one of {@see self::DEVICES} — anything else, and null, read as `both`. */
    public static function device(?string $stored): string
    {
        $value = strtolower(trim((string) $stored));

        return in_array($value, self::DEVICES, true) ? $value : self::DEVICES[0];
    }

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * Create or update a banner. Returns its id.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException with the field the operator must fix, in Arabic
     */
    public function save(array $data, ?int $bannerId): int
    {
        $storefrontId = Coerce::int($data['storefront_id'] ?? null);
        $image = trim(Coerce::str($data['image_path'] ?? ''));
        $target = Coerce::str($data['target'] ?? 'none');
        $device = self::device(Coerce::nstr($data['device'] ?? null));
        // The upload as it arrived, kept so the banner can be re-cropped without re-uploading. Empty = none kept
        // (every row written before F1, and any saved without the crop tool): the form falls back to `image_path`.
        $original = trim(Coerce::str($data['image_original_path'] ?? ''));
        $crop = self::crop($data['crop'] ?? null);
        $startsAt = self::timestamp($data['starts_at'] ?? null);
        $endsAt = self::timestamp($data['ends_at'] ?? null);

        $errors = [];

        if ($image === '') {
            $errors['image_path'] = ManageText::t('banners.image_required', 'البانر صورة. ارفع صورة قبل الحفظ.');
        }
        if (! in_array($target, self::TARGETS, true)) {
            $errors['target'] = ManageText::t('banners.target_unknown', 'وجهة غير معروفة.');
        }
        if ($endsAt !== null && $startsAt !== null && $endsAt <= $startsAt) {
            $errors['ends_at'] = ManageText::t('banners.ends_before_starts', 'تاريخ الانتهاء لازم يكون بعد تاريخ البداية.');
        }

        $productId = $target === 'product' ? Coerce::nint($data['product_id'] ?? null) : null;
        $categoryId = $target === 'category' ? Coerce::nint($data['storefront_category_id'] ?? null) : null;
        $url = $target === 'url' ? trim(Coerce::str($data['link_url'] ?? '')) : '';

        if ($target === 'product' && $productId === null) {
            $errors['product_id'] = ManageText::t('banners.choose_product', 'اختر المنتج الذي يفتحه البانر.');
        }
        if ($target === 'category' && $categoryId === null) {
            $errors['storefront_category_id'] = ManageText::t('banners.choose_category', 'اختر التصنيف الذي يفتحه البانر.');
        }
        if ($target === 'url') {
            if ($url === '') {
                $errors['link_url'] = ManageText::t('banners.url_required', 'اكتب الرابط الذي يفتحه البانر.');
            } elseif (preg_match('#^(https?://|/)#i', $url) !== 1) {
                // A relative path or an absolute http(s) URL. Anything else — `javascript:`, a bare
                // word — is either a broken link or an injection wearing one.
                $errors['link_url'] = ManageText::t('banners.url_shape', 'الرابط لازم يبدأ بـ https:// أو بـ / لصفحة داخل الموقع.');
            }
        }

        /*
         * A banner pointing at another storefront's category would render a dead link: the
         * storefront resolves a node inside its own tree and finds nothing. Checked here because
         * the screen offers only this storefront's nodes and a script would not.
         */
        if ($categoryId !== null && ! DB::table('storefront_categories')
            ->where('id', $categoryId)->where('storefront_id', $storefrontId)->exists()) {
            $errors['storefront_category_id'] = ManageText::t('banners.category_other_storefront', 'هذا التصنيف ليس في شجرة هذا المتجر.');
        }

        if ($productId !== null && ! DB::table('storefront_product')
            ->where('product_id', $productId)->where('storefront_id', $storefrontId)->exists()) {
            $errors['product_id'] = ManageText::t('banners.product_other_storefront', 'هذا المنتج غير مضاف إلى هذا المتجر، فالبانر سيفتح صفحة غير موجودة.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $row = [
            'storefront_id' => $storefrontId,
            // Home only, by decision — never taken from the payload.
            'placement' => self::PLACEMENTS[0],
            'image_path' => BannerImage::path($image),
            'device' => $device,
            'image_original_path' => $original === '' ? null : BannerImage::path($original),
            'crop' => $crop === null ? null : json_encode($crop, JSON_UNESCAPED_SLASHES),
            'link_url' => $url === '' ? null : $url,
            'product_id' => $productId,
            'storefront_category_id' => $categoryId,
            'sort_order' => Coerce::int($data['sort_order'] ?? null),
            'is_active' => Coerce::bool($data['is_active'] ?? null, true),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'updated_at' => now(),
        ];

        $id = DB::transaction(function () use ($row, $bannerId): int {
            if ($bannerId !== null) {
                DB::table('storefront_banners')->where('id', $bannerId)->update($row);

                return $bannerId;
            }

            $row['created_at'] = now();

            return (int) DB::table('storefront_banners')->insertGetId($row);
        });

        // The home page is cached per storefront; a banner nobody sees until the TTL expires is a
        // banner the operator will re-upload twice before asking.
        $this->cache->flush($storefrontId);

        return $id;
    }

    /**
     * Delete a banner. Always allowed: nothing references it, and the FILE is left alone —
     * `media:prune` is the only thing that removes from the shared tree.
     */
    public function delete(int $bannerId): void
    {
        $storefrontId = Coerce::nint(DB::table('storefront_banners')->where('id', $bannerId)->value('storefront_id'));

        DB::table('storefront_banners')->where('id', $bannerId)->delete();

        if ($storefrontId !== null) {
            $this->cache->flush($storefrontId);
        }
    }

    /**
     * The crop rectangle the tool last used, in the ORIGINAL image's own pixels, so it reopens where the operator
     * left it. Four finite non-negative numbers or nothing — a half-written rectangle is no rectangle, and this is
     * decoration for the tool, never something the storefront reads, so it fails quiet rather than refusing a save.
     *
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private static function crop(mixed $value): ?array
    {
        $raw = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (['x', 'y', 'w', 'h'] as $k) {
            $n = $raw[$k] ?? null;
            if (! is_numeric($n) || ! is_finite((float) $n) || (float) $n < 0) {
                return null;
            }
            $out[$k] = (int) round((float) $n);
        }

        return ($out['w'] < 1 || $out['h'] < 1) ? null : $out;
    }

    /** `''` and a malformed date both mean "no bound", which is what nullable columns say. */
    private static function timestamp(mixed $value): ?string
    {
        $text = trim(Coerce::str($value));
        if ($text === '') {
            return null;
        }

        $time = strtotime($text);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }
}
