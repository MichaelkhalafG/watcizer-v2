<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Content\Banners;
use App\Domain\Content\BannerState;
use App\Domain\Content\BannerWriter;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Home-page banners (wave 4D) — the screen the developer reversed their "skip it" on.
 *
 * ── One tab, home only, both storefronts ─────────────────────────────────────────────────────
 *
 * The legacy app had three placements and a combined "offers, banners and articles" section.
 * Offers became the promotions engine (§3.16), so what is left is banners and blogs, and they are
 * two different jobs with two different screens. This is the banners one, and it serves the HOME
 * slot only — {@see BannerWriter::PLACEMENTS} is the whitelist, and the column stays open for the
 * reasons written there.
 *
 * ── The list answers "is this showing?" ──────────────────────────────────────────────────────
 *
 * Same principle as the promotions list: `is_active` is what somebody typed, and a banner can be
 * switched on with a window that closed last month. {@see BannerState} turns that into one word per
 * row, and `expired` is loud because an empty hero slot is the failure nobody reports.
 */
final class BannerController
{
    public function __construct(private readonly BannerWriter $writer) {}

    public function index(Request $request, Storefront $storefront): Response|StreamedResponse
    {
        $table = TableQuery::for($request)
            ->sortable(['b.sort_order', 'b.starts_at', 'b.ends_at', 'b.id'], default: 'b.sort_order', direction: 'asc')
            ->filterable(['is_active' => ['0', '1'], 'state' => [
                BannerState::RUNNING, BannerState::SCHEDULED, BannerState::EXPIRED, BannerState::INACTIVE,
            ]])
            // Both are applied below: `state` is four predicates over two columns and a clock, and
            // `is_active` is written here as `b.is_active`.
            ->virtual(['is_active', 'state'])
            // The list as a file: the picture cannot travel, so the FILENAME does — it is the one
            // column that identifies which banner a row is when the image is gone.
            ->exportable([
                'id' => ManageText::t('common.id', 'الرقم'),
                'label' => ManageText::t('common.status', 'الحالة'),
                'starts_at' => ManageText::t('common.starts', 'يبدأ'),
                'ends_at' => ManageText::t('common.ends', 'ينتهي'),
                'target' => ManageText::t('banners.target_type', 'نوع الوجهة'),
                'destination' => ManageText::t('banners.opens', 'يفتح'),
                'sort_order' => ManageText::t('common.sort', 'الترتيب'),
                'is_active' => ManageText::t('common.active', 'مفعّل'),
                'image_path' => ManageText::t('banners.image_file', 'ملف الصورة'),
            ], 'banners');

        $filters = $table->resolvedFilters();
        $now = now()->format('Y-m-d H:i:s');

        $query = Banners::query($storefront->id);

        $active = Coerce::nstr($filters['is_active'] ?? null);
        if ($active !== null) {
            $query->where('b.is_active', $active === '1' ? 1 : 0);
        }

        match ($filters['state'] ?? null) {
            BannerState::EXPIRED => $query->whereNotNull('b.ends_at')->where('b.ends_at', '<', $now),
            BannerState::SCHEDULED => $query->whereNotNull('b.starts_at')->where('b.starts_at', '>', $now)
                ->where(fn (Builder $q) => $q->whereNull('b.ends_at')->orWhere('b.ends_at', '>=', $now)),
            BannerState::INACTIVE => $query->where('b.is_active', 0)
                ->where(fn (Builder $q) => $q->whereNull('b.ends_at')->orWhere('b.ends_at', '>=', $now)),
            BannerState::RUNNING => $query->where('b.is_active', 1)
                ->where(fn (Builder $q) => $q->whereNull('b.starts_at')->orWhere('b.starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('b.ends_at')->orWhere('b.ends_at', '>=', $now)),
            default => null,
        };

        $map = function (object $raw) use ($now, $storefront): array {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $image = Row::str($row, 'image_path');

            return [
                'id' => $id,
                'image_path' => $image,
                'image_url' => ImageUrl::src($image),
                'sort_order' => Row::int($row, 'sort_order'),
                'is_active' => Row::bool($row, 'is_active'),
                'starts_at' => Row::nstr($row, 'starts_at'),
                'ends_at' => Row::nstr($row, 'ends_at'),
                'target' => self::targetOf($row),
                'product_id' => Row::nint($row, 'product_id'),
                'storefront_category_id' => Row::nint($row, 'storefront_category_id'),
                'link_url' => Row::nstr($row, 'link_url'),
                // What it points at, in words, so the list does not make the operator open a row
                // to find out where a banner sends people.
                'destination' => self::destination($row),
                ...BannerState::of($row, $now),
                'edit_url' => route('manage.banners.index', ['storefront' => $storefront->id]).'#banner-'.$id,
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/Banners/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'table' => $table->paginate($query, $map),
            'categories' => self::categoryOptions($storefront->id),
            'targets' => BannerWriter::TARGETS,
            'media_type' => 'banner',
        ]);
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $this->writer->save($this->validated($request, $storefront), null);

        return back()->with('status', ManageText::t('banners.saved', 'تم حفظ البانر.'));
    }

    public function update(Request $request, Storefront $storefront, int $banner): RedirectResponse
    {
        self::requireBanner($storefront->id, $banner);
        $this->writer->save($this->validated($request, $storefront), $banner);

        return back()->with('status', ManageText::t('banners.updated', 'تم تحديث البانر.'));
    }

    public function destroy(Storefront $storefront, int $banner): RedirectResponse
    {
        self::requireBanner($storefront->id, $banner);
        $this->writer->delete($banner);

        return back()->with('status', ManageText::t('banners.deleted', 'تم حذف البانر. الملف نفسه لم يُحذف — media:prune وحده يفعل ذلك.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Storefront $storefront): array
    {
        $data = Coerce::arr($request->validate([
            'image_path' => ['required', 'string', 'max:255'],
            'target' => ['required', 'string', Rule::in(BannerWriter::TARGETS)],
            'product_id' => ['nullable', 'integer', 'exists:catalog_products,id'],
            'storefront_category_id' => ['nullable', 'integer', 'exists:storefront_categories,id'],
            'link_url' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]));

        // The storefront comes from the ROUTE, never from the body: a payload that could name it
        // would let one storefront's operator write a banner onto the other's home page.
        $data['storefront_id'] = $storefront->id;

        return $data;
    }

    /** 404, never 403: a banner id is guessable and a 403 would confirm the guess (§3.11.14). */
    private static function requireBanner(int $storefrontId, int $bannerId): void
    {
        abort_if(! Banners::exists($storefrontId, $bannerId), 404);
    }

    private static function targetOf(object $row): string
    {
        return match (true) {
            Row::nint(Row::cast($row), 'product_id') !== null => 'product',
            Row::nint(Row::cast($row), 'storefront_category_id') !== null => 'category',
            Row::nstr(Row::cast($row), 'link_url') !== null => 'url',
            default => 'none',
        };
    }

    /** Where this banner sends a customer, in words. */
    private static function destination(object $raw): string
    {
        $row = Row::cast($raw);

        $product = Row::nstr($row, 'product_title');
        if ($product !== null) {
            return $product;
        }
        $category = Row::nstr($row, 'category_name');
        if ($category !== null) {
            return $category;
        }
        $url = Row::nstr($row, 'link_url');

        return $url ?? '—';
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

    /** @return list<array{value: string, label: string}> */
    private static function categoryOptions(int $storefrontId): array
    {
        $rows = DB::table('storefront_categories as c')
            ->leftJoin('storefront_category_translations as t', function (JoinClause $join): void {
                $join->on('t.storefront_category_id', '=', 'c.id')->where('t.locale', '=', 'ar');
            })
            ->where('c.storefront_id', $storefrontId)
            ->where('c.is_active', true)
            ->orderBy('c.path')
            ->get(['c.id', 'c.depth', 't.name', 'c.slug']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $depth = Row::int($row, 'depth');
            $out[] = [
                'value' => (string) Row::int($row, 'id'),
                // Indented by depth, so a flat <select> still reads as a tree.
                'label' => str_repeat('— ', max(0, $depth - 1)).(Row::nstr($row, 'name') ?? Row::str($row, 'slug')),
            ];
        }

        return $out;
    }
}
