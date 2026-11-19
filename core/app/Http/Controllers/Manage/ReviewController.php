<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Domain\Activity\ActivityLog;
use App\Domain\Notifications\StorefrontMailer;
use App\Domain\Reviews\Reviews;
use App\Mail\ReviewRequestMail;
use App\Models\User;
use App\Storefront\StorefrontIdentity;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontUrls;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Carbon\Carbon;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Reviews" (SEO Phase 5, 2026-11-14; {@see Reviews}): the verified reviews customers wrote from their review links —
 * waiting, approved or rejected — with the product, the stars, the words and the photos; the team approves or rejects
 * (logged). Below, the latest review links: the WhatsApp button gives the team a fresh link to send by hand (a link is
 * stored only as a hash, so a new one replaces the old). Times are shown in Cairo time.
 *
 * ONE PAGE FOR EVERY SHOP (Michael, 2026-11-19, part 3 of the three fixes): /manage/reviews, with a shop filter (All /
 * each shop the user may manage), a Shop column, quick filters (held, stars, the last 7 days), the reviews' own approve /
 * hide / show again in the row, the product linked on its own shop; a Branches tab (every branch of those shops, its QR
 * card, its address, its WhatsApp message, its scans) and an E-mail tab (whether the review e-mail is on, when it goes,
 * the e-mail itself, and a test to the signed-in user). The same permission as before: MANAGE_LEGACY_CONTENT, per shop
 * (Roles::can) — a user scoped to one shop sees and moderates only that shop. The old per-shop address redirects here.
 */
final class ReviewController
{
    /** The statuses the quick filter names, as stored. */
    private const STATUS_FILTER = ['held' => 'pending', 'live' => 'approved', 'hidden' => 'rejected'];

    public function hub(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $shops = self::allowedShops($user);
        abort_if($shops === [], 403);
        $ids = array_column($shops, 'id');

        $table = TableQuery::for($request)
            ->sortable(['created_at', 'rating'], default: 'created_at', direction: 'desc', tiebreaker: 'id')
            ->searchable(['body', 'author_name'])
            ->filterable([
                'shop' => array_map('strval', $ids),
                'status' => array_keys(self::STATUS_FILTER),
                'stars' => ['1', '2', '3', '4', '5'],
                'recent' => ['7'],
            ])
            ->virtual(['shop', 'status', 'stars', 'recent'])
            ->perPage(default: 30, max: 100);
        $filters = $table->resolvedFilters();
        $in = $filters['shop'] !== null ? [Coerce::int($filters['shop'])] : $ids;

        $query = DB::table('product_reviews as r')->whereIn('r.storefront_id', $in)
            ->leftJoin('catalog_product_translations as en', fn (JoinClause $j) => $j->on('en.product_id', '=', 'r.product_id')->where('en.locale', '=', 'en'))
            ->leftJoin('catalog_product_translations as ar', fn (JoinClause $j) => $j->on('ar.product_id', '=', 'r.product_id')->where('ar.locale', '=', 'ar'))
            ->leftJoin('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'r.product_id')->on('sp.storefront_id', '=', 'r.storefront_id'))
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->select(['r.id', 'r.storefront_id', 'r.product_id', 'r.rating', 'r.body', 'r.author_name', 'r.photos', 'r.locale', 'r.status', 'r.created_at',
                'r.rejection_reason', 'en.title as title_en', 'ar.title as title_ar', 'o.order_number', 'sp.slug']);
        if (Reviews::guestReady()) {
            $query->addSelect(['r.source', 'r.verified', 'r.held_reason']);
        }
        if ($filters['status'] !== null) {
            $query->where('r.status', self::STATUS_FILTER[$filters['status']]);
        }
        if ($filters['stars'] !== null) {
            $query->where('r.rating', Coerce::int($filters['stars']));
        }
        if ($filters['recent'] !== null) {
            $query->where('r.created_at', '>=', now()->subDays(Coerce::int($filters['recent'])));
        }
        $byId = [];
        foreach ($shops as $shop) {
            $byId[$shop['id']] = $shop;
        }
        $map = function (object $raw) use ($byId): array {
            $row = Row::cast($raw);
            $sf = Row::int($row, 'storefront_id');
            $photos = json_decode(Row::nstr($row, 'photos') ?? '[]', true);
            $slug = Row::nstr($row, 'slug');

            return [
                'id' => Row::int($row, 'id'),
                'storefront_id' => $sf,
                'shop' => $byId[$sf]['name'] ?? (string) $sf,
                'title' => ['en' => Row::nstr($row, 'title_en') ?? '', 'ar' => Row::nstr($row, 'title_ar') ?? ''],
                // the product on its OWN shop (the review's shop), in a new tab
                'product_url' => $slug === null ? null : ($byId[$sf]['frontend'] ?? '').'/product/'.rawurlencode($slug),
                'rating' => Row::int($row, 'rating'),
                'body' => Row::str($row, 'body'),
                'name' => Row::str($row, 'author_name'),
                'photos' => is_array($photos) ? array_values(array_map(fn ($p): string => \App\Storefront\ImageUrl::srcFor($sf, Coerce::str($p)), $photos)) : [],
                'order_number' => Row::nstr($row, 'order_number'),
                'created_at' => self::cairo(Row::nstr($row, 'created_at')),
                'status' => Row::str($row, 'status'),
                'rejection_reason' => Row::nstr($row, 'rejection_reason'),
                'source' => property_exists($row, 'source') ? Row::str($row, 'source') : 'request',
                'verified' => property_exists($row, 'verified') ? (bool) Row::int($row, 'verified') : true,
                'held_reason' => property_exists($row, 'held_reason') ? Row::nstr($row, 'held_reason') : null,
            ];
        };

        $counts = [];
        foreach (DB::table('product_reviews')->whereIn('storefront_id', $in)->groupBy('status')->get([DB::raw('status'), DB::raw('COUNT(*) AS n')]) as $raw) {
            $counts[(string) array_search(Row::str(Row::cast($raw), 'status'), self::STATUS_FILTER, true)] = Row::int(Row::cast($raw), 'n');
        }
        $requests = [];
        foreach (DB::table('review_requests as q')->leftJoin('orders as o', 'o.id', '=', 'q.order_id')->whereIn('q.storefront_id', $in)
            ->orderByDesc('q.id')->limit(30)->get(['q.id', 'q.storefront_id', 'q.email', 'q.phone', 'q.sent_at', 'q.created_at', 'o.order_number']) as $raw) {
            $r = Row::cast($raw);
            $requests[] = ['id' => Row::int($r, 'id'), 'storefront_id' => Row::int($r, 'storefront_id'), 'shop' => $byId[Row::int($r, 'storefront_id')]['name'] ?? '',
                'order_number' => Row::nstr($r, 'order_number'), 'email' => Row::nstr($r, 'email') !== null,
                'phone' => Row::nstr($r, 'phone'), 'sent_at' => self::cairo(Row::nstr($r, 'sent_at')), 'created_at' => self::cairo(Row::nstr($r, 'created_at'))];
        }
        $branches = [];
        foreach ($shops as $shop) {
            $model = Storefront::query()->find($shop['id']);
            if ($model instanceof Storefront) {
                foreach (self::branches($model) as $b) {
                    $branches[] = $b + ['storefront_id' => $shop['id'], 'shop' => $shop['name'], 'shop_code' => $shop['code'], 'logo' => $shop['logo']];
                }
            }
        }

        return Inertia::render('Manage/Reviews/Index', [
            'shops' => array_map(fn (array $s): array => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name']], $shops),
            'counts' => $counts,
            'visible' => array_sum(array_map(fn (int $sf): int => Reviews::summary($sf)['count'], $in)),
            'table' => $table->paginate($query, $map),
            'requests' => $requests,
            'branches' => $branches,
            'email' => [
                'on' => (bool) config('reviews.requests_enabled'),
                'from' => self::cairo(config()->string('reviews.requests_from') !== '' ? Carbon::parse(config()->string('reviews.requests_from'))->utc()->format('Y-m-d H:i:s') : null),
                'after_days' => Coerce::int(config('reviews.after_days')),
                'within_days' => Coerce::int(config('reviews.within_days')),
                'expires_days' => Coerce::int(config('reviews.expires_days')),
                'max_per_run' => Coerce::int(config('reviews.max_per_run')),
                'to' => Coerce::str($user->getAttribute('email')),
            ],
        ]);
    }

    /** The old per-shop address (2026-11-14 to 2026-11-19): the one page, filtered on that shop. */
    public function index(Request $request, Storefront $storefront): RedirectResponse
    {
        return redirect()->route('manage.reviews.hub', ['filters' => ['shop' => (int) $storefront->id]]);
    }

    /**
     * The review e-mail as a customer gets it, for one shop (the E-mail tab's preview): the real template with an example
     * link and example products. Both languages are in the one e-mail (Arabic first).
     */
    public function emailPreview(Request $request): HttpResponse
    {
        $sf = self::shopFromRequest($request);

        return response(self::sampleMail($sf)->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    /** "Send me a test": the review e-mail of one shop, to the signed-in user's own address only (logged). */
    public function emailTest(Request $request): RedirectResponse
    {
        $sf = self::shopFromRequest($request);
        $user = $request->user();
        $to = $user instanceof User ? trim(Coerce::str($user->getAttribute('email'))) : '';
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => ManageText::t('reviews.test_no_email', 'لا يوجد بريد إلكتروني صالح لحسابك.')]);
        }
        try {
            StorefrontMailer::send($sf, $to, self::sampleMail($sf));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['email' => ManageText::t('reviews.test_failed', 'تعذّر إرسال رسالة التجربة الآن. راجع إعدادات بريد المتجر.')]);
        }
        ActivityLog::record('review_requests', 0, ActivityLog::UPDATED, [], ['test_email' => true], null, $sf);

        return back()->with('success', ManageText::t('reviews.test_sent', 'أُرسلت رسالة التجربة إلى :email.', ['email' => $to]));
    }

    private static function sampleMail(int $sf): ReviewRequestMail
    {
        return new ReviewRequestMail(
            Reviews::pageUrl($sf, 'example'),
            [ManageText::t('reviews.sample_product', 'منتج للتجربة · Example product')],
            StorefrontIdentity::for($sf),
            Reviews::googleLinks($sf),
        );
    }

    /**
     * The shop named by `?shop=` (or the form's `shop`) when this user may manage its reviews — 403 for any other shop;
     * with none named, the first shop the user manages.
     */
    private static function shopFromRequest(Request $request): int
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $allowed = array_column(self::allowedShops($user), 'id');
        abort_if($allowed === [], 403);
        if (! $request->filled('shop')) {
            return $allowed[0];
        }
        $sf = Coerce::int($request->input('shop'));
        foreach ($allowed as $id) {
            if ($id === $sf) {
                return $sf;
            }
        }
        abort(403);
    }

    /**
     * The active shops whose reviews this user may manage (MANAGE_LEGACY_CONTENT on that shop), in id order: id, code,
     * name, its shop address and its wordmark (a file of core's own, so the dashboard draws it on the QR card).
     *
     * @return list<array{id: int, code: string, name: string, frontend: string, logo: string}>
     */
    private static function allowedShops(User $user): array
    {
        $roles = app(Roles::class);
        $out = [];
        foreach (Storefront::query()->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']) as $shop) {
            $id = Coerce::int($shop->getAttribute('id'));
            if (! $roles->can($user, Role::MANAGE_LEGACY_CONTENT, $id)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'code' => Coerce::str($shop->getAttribute('code')),
                'name' => Coerce::str($shop->getAttribute('name')),
                'frontend' => rtrim(StorefrontUrls::frontend($id), '/'),
                'logo' => '/'.ltrim(StorefrontIdentity::for($id)->wordmarkPath, '/'),
            ];
        }

        return $out;
    }

    /**
     * THE BRANCHES' REVIEW LINKS (2026-11-17): per branch, its Google review link, its in-store address on our own domain
     * (/review/<key>, which counts its scans and sends on to Google), the WhatsApp message the staff copy, and the scans.
     *
     * @return list<array<string, mixed>>
     */
    private static function branches(Storefront $storefront): array
    {
        if (! DB::getSchemaBuilder()->hasColumn('storefront_pickup_stores', 'review_key')) {
            return [];
        }
        $sf = (int) $storefront->id;
        $front = rtrim(StorefrontUrls::frontend($sf), '/');
        $since = now()->timezone('Africa/Cairo')->subDays(29)->toDateString();
        $out = [];
        foreach (DB::table('storefront_pickup_stores')->where('storefront_id', $sf)->orderBy('position')->orderBy('id')->get() as $raw) {
            $r = Row::cast($raw);
            $id = Row::int($r, 'id');
            $key = Row::nstr($r, 'review_key');
            $out[] = [
                'id' => $id,
                'name' => ['en' => Row::str($r, 'name_en'), 'ar' => Row::str($r, 'name_ar')],
                'is_active' => (bool) Row::int($r, 'is_active'),
                'google_review_url' => Row::nstr($r, 'google_review_url'),
                'review_key' => $key,
                'whatsapp_review_template' => Row::nstr($r, 'whatsapp_review_template'),
                'review_link' => $key === null ? null : $front.'/review/'.$key,
                'scans_30_days' => Coerce::int(DB::table('storefront_review_scans')->where('store_id', $id)->where('day', '>=', $since)->sum('scans')),
                'scans_total' => Coerce::int(DB::table('storefront_review_scans')->where('store_id', $id)->sum('scans')),
            ];
        }

        return $out;
    }

    /** Save the branches' review links (all or nothing; logged). */
    public function branchesSave(Request $request, Storefront $storefront): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $data = Coerce::arr($request->validate([
            'branches' => ['required', 'array'],
            'branches.*.id' => ['required', 'integer'],
            'branches.*.google_review_url' => ['nullable', 'string', 'max:500', 'regex:#^https://(search\.google\.com|g\.page|www\.google\.com|maps\.app\.goo\.gl|g\.co)/\S+$#'],
            'branches.*.review_key' => ['nullable', 'string', 'regex:/^[a-z0-9-]{2,32}$/'],
            'branches.*.whatsapp_review_template' => ['nullable', 'string', 'max:1000'],
        ], [
            'branches.*.google_review_url.regex' => ManageText::t('reviews.google_url_rule', 'رابط مراجعة جوجل يبدأ بـ https:// من جوجل (search.google.com أو g.page).'),
            'branches.*.review_key.regex' => ManageText::t('reviews.key_rule', 'حروف إنجليزية صغيرة وأرقام وشرطة فقط (مثل arkadia).'),
        ]));
        $rows = Coerce::arr($data['branches']);
        $existing = DB::table('storefront_pickup_stores')->where('storefront_id', $sf)->pluck('id')->map(fn ($v): int => Coerce::int($v))->all();
        $keys = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $row = Coerce::arr($row);
            if (! in_array(Coerce::int($row['id'] ?? 0), $existing, true)) {
                $errors["branches.{$i}.id"] = ManageText::t('pickup.store_unknown', 'هذا الفرع غير موجود في هذا المتجر.');
            }
            $key = trim(Coerce::str($row['review_key'] ?? ''));
            if ($key !== '' && isset($keys[$key])) {
                $errors["branches.{$i}.review_key"] = ManageText::t('reviews.key_twice', 'هذا العنوان مستخدم لفرع آخر.');
            }
            // never a review link's own address (/review/<40 letters> is a delivery's link)
            $keys[$key] = true;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($rows, $sf): void {
            foreach ($rows as $row) {
                $row = Coerce::arr($row);
                $id = Coerce::int($row['id']);
                $before = Row::cast(DB::table('storefront_pickup_stores')->where('id', $id)->first(['name_ar', 'google_review_url', 'review_key', 'whatsapp_review_template']) ?? new \stdClass);
                $after = [
                    'google_review_url' => trim(Coerce::str($row['google_review_url'] ?? '')) ?: null,
                    'review_key' => trim(Coerce::str($row['review_key'] ?? '')) ?: null,
                    'whatsapp_review_template' => trim(Coerce::str($row['whatsapp_review_template'] ?? '')) ?: null,
                ];
                $was = [];
                $changed = [];
                foreach ($after as $k => $v) {
                    if (Row::nstr($before, $k) !== $v) {
                        $was[$k] = Row::nstr($before, $k);
                        $changed[$k] = $v;
                    }
                }
                if ($changed === []) {
                    continue;
                }
                DB::table('storefront_pickup_stores')->where('id', $id)->update($changed + ['updated_at' => now()]);
                ActivityLog::record('storefront_pickup_stores', $id, ActivityLog::UPDATED, $was, $changed, Row::str($before, 'name_ar'), $sf);
            }
        });
        // the product pages carry the Google buttons
        app(StorefrontCache::class)->flush($sf);

        return back()->with('success', ManageText::t('reviews.branches_saved', 'تم حفظ روابط التقييم للفروع.'));
    }

    /** Approve or reject a review (logged). */
    public function update(Request $request, Storefront $storefront, int $review): RedirectResponse
    {
        $data = Coerce::arr($request->validate(['status' => ['required', 'in:approved,rejected,pending'], 'reason' => ['nullable', 'string', 'max:255']]));
        $row = DB::table('product_reviews')->where('storefront_id', $storefront->id)->where('id', $review)->first(['id', 'status', 'author_name']);
        abort_if($row === null, 404);
        $status = Coerce::str($data['status']);
        Reviews::moderate($review, $status, (int) Auth::id(), isset($data['reason']) ? Coerce::str($data['reason']) : null);
        ActivityLog::record('product_reviews', $review, ActivityLog::UPDATED, ['status' => Row::str(Row::cast($row), 'status')], ['status' => $status],
            Row::str(Row::cast($row), 'author_name'), (int) $storefront->id);

        return back()->with('success', match ($status) {
            'approved' => ManageText::t('reviews.approved_done', 'التقييم ظاهر الآن في صفحة المنتج.'),
            'rejected' => ManageText::t('reviews.rejected_done', 'تم إخفاء التقييم، ولن يظهر.'),
            default => ManageText::t('reviews.pending_done', 'أعيد التقييم إلى الانتظار.'),
        });
    }

    /** A fresh review link for a request, for the team to send on WhatsApp (JSON: the link and a wa.me address). */
    public function whatsapp(Storefront $storefront, int $reviewRequest): JsonResponse
    {
        $sf = (int) $storefront->id;
        $token = Reviews::reissue($sf, $reviewRequest);
        abort_if($token === null, 404);
        $url = Reviews::pageUrl($sf, $token);
        $phone = Coerce::str(DB::table('review_requests')->where('id', $reviewRequest)->value('phone'));
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $intl = str_starts_with($digits, '0') ? '2'.$digits : $digits;                       // Egyptian 01… → 201…
        $text = ManageText::t('reviews.whatsapp_text', 'شكرًا لطلبك من :shop! يسعدنا أن تكتب رأيك في دقيقة: :url', ['shop' => (string) $storefront->name, 'url' => $url]);
        ActivityLog::record('review_requests', $reviewRequest, ActivityLog::UPDATED, [], ['whatsapp_link' => true], null, $sf);

        return response()->json(['url' => $url, 'whatsapp' => $intl === '' ? null : 'https://wa.me/'.$intl.'?text='.rawurlencode($text)]);
    }

    private static function cairo(?string $db): ?string
    {
        return $db === null ? null : Carbon::parse($db, config()->string('app.timezone'))->setTimezone('Africa/Cairo')->format('Y-m-d H:i');
    }
}
