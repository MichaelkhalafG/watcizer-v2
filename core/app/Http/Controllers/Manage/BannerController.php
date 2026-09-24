<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
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
                // Both languages of whatever the banner points at (2026-09-16).
                'destination' => ManageText::t('banners.opens_ar', 'يفتح (عربي)'),
                'destination_en' => ManageText::t('banners.opens_en', 'يفتح (إنجليزي)'),
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
                // The CSV's second language column. Never rendered — the screen reads
                // `destination` — and empty when that destination has no English translation.
                'destination_en' => self::destination($row, 'en', forExport: true),
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
        $id = $this->writer->save($this->validated($request, $storefront), null);

        $after = self::logFields($id);
        ActivityLog::record(
            'storefront_banners',
            $id,
            ActivityLog::CREATED,
            [],
            $after,
            label: self::labelOf($after),
            storefrontId: $storefront->id,
        );

        return back()->with('status', ManageText::t('banners.saved', 'تم حفظ البانر.'));
    }

    public function update(Request $request, Storefront $storefront, int $banner): RedirectResponse
    {
        self::requireBanner($storefront->id, $banner);

        // BEFORE the save, because the question this row answers is what the home page used to show
        // and where it used to send people — and the update overwrites both.
        $before = self::logFields($banner);

        $this->writer->save($this->validated($request, $storefront), $banner);

        $after = self::logFields($banner);
        ActivityLog::record(
            'storefront_banners',
            $banner,
            ActivityLog::UPDATED,
            $before,
            $after,
            label: self::labelOf($after),
            storefrontId: $storefront->id,
        );

        return back()->with('status', ManageText::t('banners.updated', 'تم تحديث البانر.'));
    }

    public function destroy(Storefront $storefront, int $banner): RedirectResponse
    {
        self::requireBanner($storefront->id, $banner);

        $before = self::logFields($banner);

        $this->writer->delete($banner);

        ActivityLog::record(
            'storefront_banners',
            $banner,
            ActivityLog::DELETED,
            $before,
            [],
            // From the snapshot, not from the table: the row is gone, and the file name is the only
            // thing left that says which banner disappeared off the home page.
            label: self::labelOf($before),
            storefrontId: $storefront->id,
        );

        return back()->with('status', ManageText::t('banners.deleted', 'تم حذف البانر. الملف نفسه لم يُحذف — media:prune وحده يفعل ذلك.'));
    }

    /**
     * What a banner is, for the log — what it POINTS AT, and whether it is showing.
     *
     * ── Why this screen is logged at all (2026-10-05) ───────────────────────────
     *
     * A banner is the first thing a customer sees, and every failure it has is silent: an empty
     * hero slot, a link that goes nowhere, a window that closed. Nothing here recorded anything, so
     * "the offer banner is gone" and "the banner sends people to the wrong category" both had the
     * same answer — nobody knows, and nobody knows what it pointed at before.
     *
     * The three destination columns are all kept even though a banner carries exactly ONE of them:
     * a change of TARGET is a change from one column to another, and a snapshot of only the
     * populated one would record the arrival and lose the departure.
     *
     * Out: `placement` (always `home`, by decision — a constant is not a change), `storefront_id`
     * (from the route, and already on the row as `storefront_id`), and `updated_at` — that last one
     * deliberately, because it moves on every save and would make every save write a row saying
     * nothing else changed, which is exactly the noise `ActivityLog::diff()` exists to suppress.
     *
     * @return array<string, mixed>
     */
    private static function logFields(int $id): array
    {
        $raw = DB::table('storefront_banners')->where('id', $id)->first([
            'image_path', 'product_id', 'storefront_category_id', 'link_url',
            'sort_order', 'is_active', 'starts_at', 'ends_at',
        ]);
        if (! is_object($raw)) {
            return [];
        }
        $row = Row::cast($raw);

        return [
            'image_path' => Row::str($row, 'image_path'),
            'product_id' => Row::nint($row, 'product_id'),
            'storefront_category_id' => Row::nint($row, 'storefront_category_id'),
            'link_url' => Row::nstr($row, 'link_url'),
            'sort_order' => Row::int($row, 'sort_order'),
            'is_active' => Row::bool($row, 'is_active'),
            'starts_at' => Row::nstr($row, 'starts_at'),
            'ends_at' => Row::nstr($row, 'ends_at'),
        ];
    }

    /**
     * What to call a banner in the log.
     *
     * A banner has no name — it is a picture — so the FILENAME is its label, for the same reason
     * the CSV export carries it: it is the one column that identifies which banner a row is when
     * the image itself cannot travel.
     *
     * @param  array<string, mixed>  $fields
     */
    private static function labelOf(array $fields): ?string
    {
        return Coerce::nstr($fields['image_path'] ?? null);
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

    /**
     * Where this banner sends a customer, in words, in ONE language.
     *
     * `$locale` picks which translation to read. The screen asks for Arabic; the CSV asks for both
     * and puts them in two columns, because an export that carries one language loses half the
     * record for whoever opens it to bulk-edit or to send on.
     *
     * A URL destination is not translated — it is the same link in either column — and a banner
     * with no destination at all yields an EMPTY string for the export rather than the screen's
     * em-dash, because a dash in a spreadsheet cell is a value and an empty cell is a fact.
     */
    private static function destination(object $raw, string $locale = 'ar', bool $forExport = false): string
    {
        $row = Row::cast($raw);
        $suffix = $locale === 'en' ? '_en' : '';

        $product = Row::nstr($row, 'product_title'.$suffix);
        if ($product !== null) {
            return $product;
        }
        $category = Row::nstr($row, 'category_name'.$suffix);
        if ($category !== null) {
            return $category;
        }

        /*
         * A product or category that HAS a destination but no translation in this locale must read
         * as empty, not fall through to the link. Falling through would put the URL in the English
         * column of a banner that points at a product — which is not a missing translation, it is a
         * different fact.
         */
        if (Row::nint($row, 'product_id') !== null || Row::nint($row, 'storefront_category_id') !== null) {
            return $forExport ? '' : '—';
        }

        $url = Row::nstr($row, 'link_url');

        return $url ?? ($forExport ? '' : '—');
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
