<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Reviews\Reviews;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Reviews" (SEO Phase 5, 2026-11-14; {@see Reviews}): the verified reviews customers wrote from their review links —
 * waiting, approved or rejected — with the product, the stars, the words and the photos; the team approves or rejects
 * (logged). Below, the latest review links: the WhatsApp button gives the team a fresh link to send by hand (a link is
 * stored only as a hash, so a new one replaces the old). Times are shown in Cairo time.
 */
final class ReviewController
{
    public function index(Request $request, Storefront $storefront): Response
    {
        $sf = (int) $storefront->id;
        $table = TableQuery::for($request)
            ->sortable(['created_at', 'rating'], default: 'created_at', direction: 'desc', tiebreaker: 'id')
            ->searchable(['body', 'author_name'])
            ->filterable(['status' => Reviews::STATUSES])
            ->virtual(['status'])
            ->perPage(default: 30, max: 100);
        $status = $table->resolvedFilters()['status'] ?? 'pending';
        $query = DB::table('product_reviews as r')->where('r.storefront_id', $sf)->where('r.status', $status)
            ->leftJoin('catalog_product_translations as en', fn (JoinClause $j) => $j->on('en.product_id', '=', 'r.product_id')->where('en.locale', '=', 'en'))
            ->leftJoin('catalog_product_translations as ar', fn (JoinClause $j) => $j->on('ar.product_id', '=', 'r.product_id')->where('ar.locale', '=', 'ar'))
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->select(['r.id', 'r.product_id', 'r.rating', 'r.body', 'r.author_name', 'r.photos', 'r.locale', 'r.status', 'r.created_at', 'r.rejection_reason',
                'en.title as title_en', 'ar.title as title_ar', 'o.order_number']);
        $map = function (object $raw) use ($sf): array {
            $row = Row::cast($raw);
            $photos = json_decode(Row::nstr($row, 'photos') ?? '[]', true);

            return [
                'id' => Row::int($row, 'id'),
                'title' => ['en' => Row::nstr($row, 'title_en') ?? '', 'ar' => Row::nstr($row, 'title_ar') ?? ''],
                'rating' => Row::int($row, 'rating'),
                'body' => Row::str($row, 'body'),
                'name' => Row::str($row, 'author_name'),
                'photos' => is_array($photos) ? array_values(array_map(fn ($p): string => \App\Storefront\ImageUrl::srcFor($sf, Coerce::str($p)), $photos)) : [],
                'order_number' => Row::nstr($row, 'order_number'),
                'created_at' => self::cairo(Row::nstr($row, 'created_at')),
                'rejection_reason' => Row::nstr($row, 'rejection_reason'),
            ];
        };
        $requests = [];
        foreach (DB::table('review_requests as q')->leftJoin('orders as o', 'o.id', '=', 'q.order_id')->where('q.storefront_id', $sf)
            ->orderByDesc('q.id')->limit(30)->get(['q.id', 'q.email', 'q.phone', 'q.sent_at', 'q.created_at', 'o.order_number']) as $raw) {
            $r = Row::cast($raw);
            $requests[] = ['id' => Row::int($r, 'id'), 'order_number' => Row::nstr($r, 'order_number'), 'email' => Row::nstr($r, 'email') !== null,
                'phone' => Row::nstr($r, 'phone'), 'sent_at' => self::cairo(Row::nstr($r, 'sent_at')), 'created_at' => self::cairo(Row::nstr($r, 'created_at'))];
        }

        return Inertia::render('Manage/Reviews/Index', [
            'storefront' => ['id' => $sf, 'code' => $storefront->code, 'name' => $storefront->name],
            'status' => $status,
            'counts' => array_map(fn ($n): int => Coerce::int($n), DB::table('product_reviews')->where('storefront_id', $sf)->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status')->all()),
            'summary' => Reviews::summary($sf),
            'emails_on' => (bool) config('reviews.requests_enabled'),
            'table' => $table->paginate($query, $map),
            'requests' => $requests,
        ]);
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
            'approved' => ManageText::t('reviews.approved_done', 'تمت الموافقة على التقييم، وهو يظهر الآن في صفحة المنتج.'),
            'rejected' => ManageText::t('reviews.rejected_done', 'تم رفض التقييم، ولن يظهر.'),
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
