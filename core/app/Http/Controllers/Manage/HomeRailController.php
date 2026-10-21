<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Activity\ActivityLog;
use App\Domain\Content\HomeRails;
use App\Domain\Inventory\InventoryService;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * /manage/storefronts/{storefront}/home-rails — which product rails the home page shows, in what
 * order (C-1 stage 4, slice C; table `storefront_home_rails`, M1y).
 *
 * Until this screen the home page's rails were fixed in the storefront's code: offers, a featured
 * block, then one rail per grade in the grades table's order. Here a rail can be added (a grade, a
 * brand, a top-level category, offers, featured, newest — or `custom`, products picked by hand with
 * the shared ProductPicker, M2e 2026-10-02), switched off, retitled, given a card count, moved up or
 * down, or deleted.
 *
 * ── Every write BUSTS the storefront's cache — it did not, and that was a bug (D2, 2026-10-20) ──
 *
 * This block used to say the opposite, and it was right when it was written: compat's `catalog/home`
 * reads this table on every request, so a save was live in core at once and flushing would have
 * thrown away the listing index for nothing. **v2 changed that on 2026-10-17.** `StorefrontHome`
 * assembles the whole home payload — rails, deals, categories, banner, brands — and caches it under
 * the storefront's version for `storefront.ttl.count` (600 s). Nothing here bumped that version, so a
 * reorder reached the database and no further: measured on a scratch copy, the row moved to position
 * 10 while `GET /api/v2/brandfashion/home` went on serving the old order, twice, outside any browser.
 * Brand Fashion's home reads v2; Watchizer's still reads compat, which is why compat answered the new
 * order in the same test.
 *
 * So every write flushes, exactly as the banners screen does ({@see \App\Domain\Content\BannerWriter}).
 * What it costs: the storefront's other cached v2 entries are rebuilt on their next request. What it
 * buys: the screen's own promise — "a change reaches the shop within 5 minutes" — is true again,
 * bounded by the storefront's `revalidate = 300` and nothing else.
 *
 * Every write is activity-logged against `storefront_home_rails`. Same scope and ability as the
 * banners screen: it decides what one storefront's home page SHOWS.
 */
final class HomeRailController
{
    /**
     * The storefront draws a rail only with at least this many products (item 14, the owner's threshold).
     * It is four because the card grid is four to a row at desktop width. Mirrors MIN_RAIL_PRODUCTS in
     * HomeClient.jsx, which is the rule that actually decides; this screen only reports against it.
     */
    public const MIN_PRODUCTS = 4;

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * How many products each rail would ACTUALLY render right now, keyed by rail id.
     *
     * The storefront's own resolver is used ({@see CompatHome::rails()}), not a second copy of the rule, so
     * this screen cannot disagree with the page. A rail the resolver drops entirely is 0.
     *
     * @return array<int, int>
     */
    private static function liveCounts(int $storefrontId): array
    {
        $compat = new CompatServices(app(StorefrontCache::class), app(InventoryService::class), CompatStorefront::pinned($storefrontId));
        $out = [];
        foreach ($compat->home->rails() as $rail) {
            $out[$rail['id']] = count($rail['products']);
        }

        return $out;
    }

    /**
     * The flash message after a save: `$ok` as given, or a WARNING when the rail that was just saved
     * would not be drawn (item 14, 2026-10-23).
     *
     * It warns and still saves. Refusing would block an operator building a rail they are about to fill, and
     * it would still not keep the promise — a rail that passes today falls below the line on its own when
     * stock moves. The storefront's render rule is the guard; this is so the mistake is not made unknowingly.
     */
    private static function savedMessage(int $storefrontId, int $railId, string $ok): string
    {
        $count = self::liveCounts($storefrontId)[$railId] ?? 0;
        if ($count >= self::MIN_PRODUCTS) {
            return $ok;
        }

        return ManageText::t(
            'home_rails.saved_too_few',
            'تم حفظ الشريط، لكنه لن يظهر في الصفحة الرئيسية: :count منتج فقط، والحد الأدنى :min.',
            ['count' => $count, 'min' => self::MIN_PRODUCTS],
        );
    }

    public function index(Storefront $storefront): Response
    {
        $targets = self::targetOptions($storefront->id);
        $counts = self::liveCounts($storefront->id);
        $rails = [];
        foreach (HomeRails::all($storefront->id) as $rail) {
            $rails[] = $rail + [
                /*
                 * What this rail renders TODAY, and whether that is enough to be drawn (item 14, 2026-10-23).
                 *
                 * The storefront hides a rail under MIN_PRODUCTS because a band that cannot fill one row of
                 * cards is mostly empty under its own heading. The count moves with stock and visibility
                 * without anyone editing the rail, so this screen does not PREVENT a short rail — it shows
                 * the truth every time the list is looked at, and the render rule stays the real guard.
                 */
                'product_count' => $counts[$rail['id']] ?? 0,
                // What the rail shows, in words, so the list reads without opening a row.
                'target_name' => match (true) {
                    $rail['kind'] === HomeRails::CUSTOM => ManageText::t('home_rails.picked_count', ':count منتجات مختارة', ['count' => count($rail['product_ids'])]),
                    $rail['target_id'] === null => null,
                    default => self::optionLabel($targets[$rail['kind']] ?? [], $rail['target_id']) ?? ManageText::t('home_rails.target_missing', 'غير موجود'),
                },
            ];
        }

        return Inertia::render('Manage/HomeRails/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'rails' => $rails,
            'kinds' => HomeRails::KINDS,
            'targeted' => HomeRails::TARGETED,
            'targets' => $targets,
            'max_cards' => HomeRails::MAX_CARDS,
            'min_products' => self::MIN_PRODUCTS,
        ]);
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $id = self::write(fn (array $data): int => HomeRails::create($storefront->id, $data), $this->validated($request, $storefront));

        $after = self::logFields($storefront->id, $id);
        ActivityLog::record('storefront_home_rails', $id, ActivityLog::CREATED, [], $after, label: self::labelOf($after), storefrontId: $storefront->id);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', self::savedMessage((int) $storefront->id, $id, ManageText::t('home_rails.saved', 'تم حفظ الشريط.')));
    }

    public function update(Request $request, Storefront $storefront, int $rail): RedirectResponse
    {
        self::requireRail($storefront->id, $rail);
        $before = self::logFields($storefront->id, $rail);

        self::write(function (array $data) use ($storefront, $rail): int {
            HomeRails::update($storefront->id, $rail, $data);

            return $rail;
        }, $this->validated($request, $storefront));

        $after = self::logFields($storefront->id, $rail);
        ActivityLog::record('storefront_home_rails', $rail, ActivityLog::UPDATED, $before, $after, label: self::labelOf($after), storefrontId: $storefront->id);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', self::savedMessage((int) $storefront->id, $rail, ManageText::t('home_rails.updated', 'تم تحديث الشريط.')));
    }

    public function destroy(Storefront $storefront, int $rail): RedirectResponse
    {
        self::requireRail($storefront->id, $rail);
        $before = self::logFields($storefront->id, $rail);

        HomeRails::delete($storefront->id, $rail);

        ActivityLog::record('storefront_home_rails', $rail, ActivityLog::DELETED, $before, [], label: self::labelOf($before), storefrontId: $storefront->id);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('home_rails.deleted', 'تم حذف الشريط.'));
    }

    /**
     * The whole order at once — the screen sends every rail's id after a move. One log row per rail
     * whose position changed, so "who moved the offers rail to the bottom" has an answer.
     */
    public function reorder(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate(['order' => ['required', 'array', 'max:200'], 'order.*' => ['integer']]);
        $ids = Coerce::orderedIntList($request->input('order'));

        $before = [];
        foreach (HomeRails::all($storefront->id) as $rail) {
            $before[$rail['id']] = self::logFields($storefront->id, $rail['id']);
        }
        try {
            HomeRails::reorder($storefront->id, $ids);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['order' => ManageText::t('home_rails.order_stale', 'تغيّرت قائمة الأشرطة في مكان آخر. أعد تحميل الصفحة ثم رتّب من جديد.')]);
        }
        foreach ($before as $id => $old) {
            $new = self::logFields($storefront->id, $id);
            if (($new['position'] ?? null) !== ($old['position'] ?? null)) {
                ActivityLog::record('storefront_home_rails', $id, ActivityLog::UPDATED, $old, $new, label: self::labelOf($new), storefrontId: $storefront->id);
            }
        }
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('home_rails.reordered', 'تم حفظ الترتيب.'));
    }

    /**
     * @param  callable(array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}): int  $write
     * @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}  $data
     */
    private static function write(callable $write, array $data): int
    {
        try {
            return $write($data);
        } catch (InvalidArgumentException) {
            // `validated()` already refuses everything HomeRails refuses; this is the second line.
            throw ValidationException::withMessages(['kind' => ManageText::t('home_rails.invalid', 'هذا الشريط غير صالح.')]);
        }
    }

    /**
     * @return array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}
     */
    private function validated(Request $request, Storefront $storefront): array
    {
        $data = Coerce::arr($request->validate([
            'kind' => ['required', 'string', Rule::in(HomeRails::KINDS)],
            'target_id' => ['nullable', 'integer'],
            'title_en' => ['nullable', 'string', 'max:120'],
            'title_ar' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'card_count' => ['required', 'integer', 'min:1', 'max:'.HomeRails::MAX_CARDS],
            // A custom rail's picks (M2e): products placed on THIS storefront, in order.
            'product_ids' => ['nullable', 'array', 'max:'.HomeRails::MAX_CARDS],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('storefront_product', 'product_id')->where('storefront_id', $storefront->id)],
        ], [
            'product_ids.*.exists' => ManageText::t('home_rails.pick_foreign', 'هذا المنتج غير موجود في هذا المتجر.'),
            'product_ids.max' => ManageText::t('home_rails.picks_max', 'يمكن اختيار :max منتجًا على الأكثر.', ['max' => HomeRails::MAX_CARDS]),
        ]));
        $kind = Coerce::str($data['kind'] ?? null);
        $target = Coerce::nint($data['target_id'] ?? null);

        if (in_array($kind, HomeRails::TARGETED, true)) {
            // The target must be one this storefront can show — the same list the form offers.
            $options = self::targetOptions($storefront->id)[$kind] ?? [];
            if ($target === null || self::optionLabel($options, $target) === null) {
                throw ValidationException::withMessages(['target_id' => ManageText::t('home_rails.choose_target', 'اختر ما يعرضه الشريط.')]);
            }
        } else {
            $target = null;                                   // offers, featured, newest and custom take none
        }

        $picks = Coerce::orderedIntList($data['product_ids'] ?? []);
        if ($kind === HomeRails::CUSTOM) {
            // Said field by field, before HomeRails' own refusal (which only says "invalid").
            $missing = [];
            if ($picks === []) {
                $missing['product_ids'] = ManageText::t('home_rails.picks_required', 'اختر منتجًا واحدًا على الأقل لهذا الشريط.');
            }
            foreach (['title_ar' => ManageText::t('home_rails.title_ar_required', 'اكتب عنوان الشريط بالعربية: الشريط المختار يدويًا ليس له اسم تلقائي.'),
                'title_en' => ManageText::t('home_rails.title_en_required', 'اكتب عنوان الشريط بالإنجليزية: الشريط المختار يدويًا ليس له اسم تلقائي.')] as $field => $message) {
                if (trim(Coerce::str($data[$field] ?? null)) === '') {
                    $missing[$field] = $message;
                }
            }
            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        return [
            'kind' => $kind,
            'target_id' => $target,
            'product_ids' => $kind === HomeRails::CUSTOM ? $picks : null,
            'title_en' => Coerce::nstr($data['title_en'] ?? null),
            'title_ar' => Coerce::nstr($data['title_ar'] ?? null),
            'is_active' => Coerce::bool($data['is_active'] ?? null),
            'card_count' => Coerce::int($data['card_count'] ?? null),
        ];
    }

    /** 404, never 403: a rail id is guessable and a 403 would confirm the guess (§3.11.14). */
    private static function requireRail(int $storefrontId, int $railId): void
    {
        abort_if(HomeRails::find($storefrontId, $railId) === null, 404);
    }

    /**
     * What a rail is, for the log. `updated_at` is left out so a save that changes nothing writes
     * nothing (`ActivityLog::diff()`).
     *
     * @return array{kind: string, target_id: ?int, title_en: ?string, title_ar: ?string, position: int, is_active: bool, card_count: int}|array{}
     */
    private static function logFields(int $storefrontId, int $id): array
    {
        $rail = HomeRails::find($storefrontId, $id);
        if ($rail === null) {
            return [];
        }
        unset($rail['id']);

        return $rail;
    }

    /**
     * What to call a rail in the log: its kind, and its target's id when it has one.
     *
     * @param  array<string, mixed>  $fields
     */
    private static function labelOf(array $fields): ?string
    {
        $kind = Coerce::nstr($fields['kind'] ?? null);
        if ($kind === null) {
            return null;
        }
        $target = Coerce::nint($fields['target_id'] ?? null);

        return $target === null ? $kind : "{$kind} #{$target}";
    }

    /**
     * The targets the form offers, per targeted kind, named in the operator's language.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private static function targetOptions(int $storefrontId): array
    {
        $named = function (string $master, string $translations, string $key): array {
            $rows = DB::table($master.' as m')
                ->leftJoin($translations.' as ar', function (JoinClause $join) use ($key): void {
                    $join->on("ar.{$key}", '=', 'm.id')->where('ar.locale', '=', 'ar');
                })
                ->leftJoin($translations.' as en', function (JoinClause $join) use ($key): void {
                    $join->on("en.{$key}", '=', 'm.id')->where('en.locale', '=', 'en');
                })
                ->orderBy('m.id')
                ->get(['m.id', 'ar.name as name_ar', 'en.name as name_en']);

            return self::options($rows);
        };

        $categories = DB::table('storefront_categories as c')
            ->leftJoin('storefront_category_translations as ar', function (JoinClause $join): void {
                $join->on('ar.storefront_category_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as en', function (JoinClause $join): void {
                $join->on('en.storefront_category_id', '=', 'c.id')->where('en.locale', '=', 'en');
            })
            ->where('c.storefront_id', $storefrontId)
            ->where('c.depth', 1)
            ->where('c.is_active', true)
            // The legacy tree's placeholder root is not a category a shopper can browse.
            ->where(fn (Builder $q) => $q->whereNull('c.legacy_source')->orWhere('c.legacy_source', '!=', 'category_root'))
            ->orderBy('c.sort_order')->orderBy('c.id')
            ->get(['c.id', 'ar.name as name_ar', 'en.name as name_en']);

        return [
            HomeRails::GRADE => $named('catalog_grades', 'catalog_grade_translations', 'grade_id'),
            HomeRails::BRAND => $named('catalog_brands', 'catalog_brand_translations', 'brand_id'),
            HomeRails::CATEGORY_TYPE => self::options($categories),
        ];
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{value: string, label: string}>
     */
    private static function options(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $out[] = [
                'value' => (string) Row::int($row, 'id'),
                // In the operator's language, the other one when that is missing (the name seam).
                'label' => LocalisedName::pick(Row::nstr($row, 'name_ar'), Row::nstr($row, 'name_en'), '#'.Row::int($row, 'id')),
            ];
        }

        return $out;
    }

    /** @param  list<array{value: string, label: string}>  $options */
    private static function optionLabel(array $options, int $id): ?string
    {
        foreach ($options as $option) {
            if ($option['value'] === (string) $id) {
                return $option['label'];
            }
        }

        return null;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (Storefront::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']) as $storefront) {
            $out[] = ['value' => (string) Coerce::int($storefront->getAttribute('id')), 'label' => Coerce::str($storefront->getAttribute('name'))];
        }

        return $out;
    }
}
