<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Campaigns\Campaigns;
use App\Domain\Campaigns\CampaignWriter;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Timed campaigns — Hot Deals and Flash Sale — for one storefront (the twelve updates, Part 2.1, 2026-11-05).
 *
 * ONE page, as the brief asks: the list of this storefront's campaigns and the form for one, with
 * exactly the five things a campaign has — a name, a presentation, a product selection, a start and
 * an end, and the active toggle. Everything the storefront then shows (the slider, the page, the
 * countdown, the header switch, the progress bar) follows from those five; there is nothing else to
 * configure and so nothing else to get wrong.
 *
 * The rules live in {@see CampaignWriter}; this controller turns each refusal into a sentence ON the
 * field that caused it (AGENTS §2.27), logs every write, and flushes the storefront so a save is on
 * the site at once. Same ability and scope as the banners and the home rails: it decides what one
 * storefront's home page shows.
 */
final class CampaignController
{
    public function __construct(private readonly StorefrontCache $cache) {}

    public function index(Storefront $storefront): Response
    {
        $rows = [];
        foreach (
            DB::table('storefront_campaigns')->where('storefront_id', $storefront->id)
                ->orderByDesc('starts_at')->orderByDesc('id')->get() as $raw
        ) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $active = Row::bool($row, 'is_active');
            $startsAt = Row::str($row, 'starts_at');
            $endsAt = Row::str($row, 'ends_at');
            $progress = Campaigns::progress(['id' => $id, 'ends_at' => $endsAt]);

            $rows[] = [
                'id' => $id,
                'name' => Row::str($row, 'name'),
                'presentation' => Row::str($row, 'presentation'),
                // `datetime-local` form: what the two inputs read and write, in the shop's own time.
                'starts_at' => Carbon::parse($startsAt)->format('Y-m-d\TH:i'),
                'ends_at' => Carbon::parse($endsAt)->format('Y-m-d\TH:i'),
                'is_active' => $active,
                'state' => Campaigns::state($active, $startsAt, $endsAt),
                'product_ids' => Campaigns::productIds($id),
                // The REAL numbers behind the storefront's bar, for the team: how many products have a
                // recorded start, how many units they started with, how many of those were sold.
                'recorded' => count($progress),
                'started_units' => array_sum(array_column($progress, 'start')),
                'sold_units' => array_sum(array_column($progress, 'sold')),
                'activated_at' => Row::nstr($row, 'activated_at'),
            ];
        }

        return Inertia::render('Manage/Campaigns/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'campaigns' => $rows,
            'presentations' => Campaigns::PRESENTATIONS,
            'max_products' => Campaigns::MAX_PRODUCTS,
            // The shop's clock, so "starts now" on the form means now in the time zone the dates are stored in.
            'now' => now()->format('Y-m-d\TH:i'),
            'timezone' => config()->string('app.timezone'),
        ]);
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = $this->validated($request);
        $id = self::write(fn (): int => CampaignWriter::create($storefront->id, $data, Coerce::nint($request->user()?->getAuthIdentifier())), $storefront->id, null, $data);

        $after = self::logFields($id);
        ActivityLog::record('storefront_campaigns', $id, ActivityLog::CREATED, [], $after, label: Coerce::nstr($after['name'] ?? null), storefrontId: $storefront->id);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('campaigns.saved', 'تم حفظ الحملة.'));
    }

    public function update(Request $request, Storefront $storefront, int $campaign): RedirectResponse
    {
        self::requireCampaign($storefront->id, $campaign);
        $data = $this->validated($request);
        $before = self::logFields($campaign);

        self::write(function () use ($storefront, $campaign, $data, $request): int {
            CampaignWriter::update($storefront->id, $campaign, $data, Coerce::nint($request->user()?->getAuthIdentifier()));

            return $campaign;
        }, $storefront->id, $campaign, $data);

        $after = self::logFields($campaign);
        ActivityLog::record('storefront_campaigns', $campaign, ActivityLog::UPDATED, $before, $after, label: Coerce::nstr($after['name'] ?? null), storefrontId: $storefront->id);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('campaigns.saved', 'تم حفظ الحملة.'));
    }

    public function destroy(Storefront $storefront, int $campaign): RedirectResponse
    {
        self::requireCampaign($storefront->id, $campaign);
        $before = self::logFields($campaign);

        CampaignWriter::delete($storefront->id, $campaign);

        ActivityLog::record('storefront_campaigns', $campaign, ActivityLog::DELETED, $before, [], label: Coerce::nstr($before['name'] ?? null), storefrontId: $storefront->id);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('campaigns.deleted', 'تم حذف الحملة.'));
    }

    /**
     * Run a write and turn the writer's refusal into the sentence for the field it names.
     *
     * @param  callable(): int  $write
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>}  $data
     */
    private static function write(callable $write, int $storefrontId, ?int $campaignId, array $data): int
    {
        try {
            return $write();
        } catch (InvalidArgumentException $e) {
            $other = $e->getMessage() === 'overlap'
                ? CampaignWriter::overlapping($storefrontId, $campaignId, $data['presentation'], $data['starts_at'], $data['ends_at'])
                : null;

            throw ValidationException::withMessages(match ($e->getMessage()) {
                'name' => ['name' => ManageText::t('campaigns.error_name', 'اكتب اسمًا للحملة.')],
                'presentation' => ['presentation' => ManageText::t('campaigns.error_presentation', 'اختر شكل العرض: عروض ساخنة أو تخفيضات خاطفة.')],
                'ends_at' => ['ends_at' => ManageText::t('campaigns.error_window', 'يجب أن تكون النهاية بعد البداية.')],
                'product_ids' => ['product_ids' => ManageText::t('campaigns.error_products', 'اختر من منتج واحد إلى :max منتج، بلا تكرار.', ['max' => Campaigns::MAX_PRODUCTS])],
                'product_ids.placed' => ['product_ids' => ManageText::t('campaigns.error_products_placed', 'أحد المنتجات المختارة غير معروض على هذا المتجر أو حُذف. أزله من القائمة.')],
                'overlap' => ['starts_at' => ManageText::t(
                    'campaigns.error_overlap',
                    'تتداخل هذه الفترة مع حملة مفعّلة من الشكل نفسه: «:name». لا يمكن أن تعمل حملتان من الشكل نفسه في وقت واحد — غيّر التاريخين أو أوقف الأخرى.',
                    ['name' => $other ?? '—'],
                )],
                default => ['name' => ManageText::t('campaigns.error_invalid', 'هذه الحملة غير صالحة.')],
            });
        }
    }

    /** @return array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>} */
    private function validated(Request $request): array
    {
        $data = Coerce::arr($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'presentation' => ['required', 'string', Rule::in(Campaigns::PRESENTATIONS)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
            'product_ids' => ['required', 'array', 'min:1', 'max:'.Campaigns::MAX_PRODUCTS],
            'product_ids.*' => ['integer', 'min:1', 'distinct'],
        ], [
            'ends_at.after' => ManageText::t('campaigns.error_window', 'يجب أن تكون النهاية بعد البداية.'),
        ]));

        $ids = [];
        foreach (Coerce::arr($data['product_ids'] ?? []) as $id) {
            $ids[] = Coerce::int($id);
        }

        return [
            'name' => Coerce::str($data['name'] ?? null),
            'presentation' => Coerce::str($data['presentation'] ?? null),
            // Stored as the shop's wall clock, seconds dropped: the form has none and a countdown that ends
            // at :37 seconds would only ever be an accident.
            'starts_at' => Carbon::parse(Coerce::str($data['starts_at'] ?? null))->format('Y-m-d H:i:00'),
            'ends_at' => Carbon::parse(Coerce::str($data['ends_at'] ?? null))->format('Y-m-d H:i:00'),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'product_ids' => $ids,
        ];
    }

    private static function requireCampaign(int $storefrontId, int $campaignId): void
    {
        abort_unless(DB::table('storefront_campaigns')->where('storefront_id', $storefrontId)->where('id', $campaignId)->exists(), 404);
    }

    /**
     * What the activity log compares: the five things a campaign is.
     *
     * @return array<string, mixed>
     */
    private static function logFields(int $campaignId): array
    {
        $raw = DB::table('storefront_campaigns')->where('id', $campaignId)->first(['name', 'presentation', 'starts_at', 'ends_at', 'is_active']);
        if (! is_object($raw)) {
            return [];
        }

        return [
            'name' => Row::str($raw, 'name'),
            'presentation' => Row::str($raw, 'presentation'),
            'starts_at' => Row::str($raw, 'starts_at'),
            'ends_at' => Row::str($raw, 'ends_at'),
            'is_active' => Row::bool($raw, 'is_active'),
            'product_ids' => Campaigns::productIds($campaignId),
        ];
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
