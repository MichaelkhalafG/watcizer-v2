<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\HomeRails;
use App\Models\Storefront\Storefront;
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
 * brand, a top-level category, offers, featured, newest), switched off, retitled, given a card
 * count, moved up or down, or deleted.
 *
 * ── What it does NOT need to bust ────────────────────────────────────────────────────────────
 *
 * `catalog/home` reads this table on every request — the rails are a handful of rows, and the
 * cards come from caches that are already per product — so a save is live in core at once. The
 * storefront's home page is regenerated at most every 5 minutes (Next `revalidate = 300`), and
 * that is the only delay. Flushing the storefront cache would throw away the listing index for
 * nothing, so it is deliberately not done.
 *
 * Every write is activity-logged against `storefront_home_rails`. Same scope and ability as the
 * banners screen: it decides what one storefront's home page SHOWS.
 */
final class HomeRailController
{
    public function index(Storefront $storefront): Response
    {
        $targets = self::targetOptions($storefront->id);
        $rails = [];
        foreach (HomeRails::all($storefront->id) as $rail) {
            $rails[] = $rail + [
                // What the rail shows, in words, so the list reads without opening a row.
                'target_name' => $rail['target_id'] === null ? null
                    : (self::optionLabel($targets[$rail['kind']] ?? [], $rail['target_id']) ?? ManageText::t('home_rails.target_missing', 'غير موجود')),
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
        ]);
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $id = self::write(fn (array $data): int => HomeRails::create($storefront->id, $data), $this->validated($request, $storefront));

        $after = self::logFields($storefront->id, $id);
        ActivityLog::record('storefront_home_rails', $id, ActivityLog::CREATED, [], $after, label: self::labelOf($after), storefrontId: $storefront->id);

        return back()->with('status', ManageText::t('home_rails.saved', 'تم حفظ الشريط.'));
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

        return back()->with('status', ManageText::t('home_rails.updated', 'تم تحديث الشريط.'));
    }

    public function destroy(Storefront $storefront, int $rail): RedirectResponse
    {
        self::requireRail($storefront->id, $rail);
        $before = self::logFields($storefront->id, $rail);

        HomeRails::delete($storefront->id, $rail);

        ActivityLog::record('storefront_home_rails', $rail, ActivityLog::DELETED, $before, [], label: self::labelOf($before), storefrontId: $storefront->id);

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

        return back()->with('status', ManageText::t('home_rails.reordered', 'تم حفظ الترتيب.'));
    }

    /**
     * @param  callable(array{kind: string, target_id: ?int, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}): int  $write
     * @param  array{kind: string, target_id: ?int, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}  $data
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
     * @return array{kind: string, target_id: ?int, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}
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
            $target = null;                                   // offers, featured and newest take none
        }

        return [
            'kind' => $kind,
            'target_id' => $target,
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
