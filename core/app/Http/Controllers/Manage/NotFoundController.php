<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontUrls;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Pages not found" (SEO Phase 1, 2026-11-14): the addresses a shop answered 404 for, most asked first, with how many
 * of the asks were search engines, when first and last, and the page that linked there ({@see \App\Domain\Seo\NotFoundLog}).
 * The team fixes the link or the page, then marks the row fixed; a fixed path asked for again comes back open.
 * Times are shown in Cairo time (the database keeps the app's time zone).
 */
final class NotFoundController
{
    public function index(Request $request, Storefront $storefront): Response|StreamedResponse
    {
        $sf = (int) $storefront->id;
        $table = TableQuery::for($request)
            ->sortable(['hits', 'bot_hits', 'last_seen_at', 'first_seen_at', 'path'], default: 'hits', direction: 'desc', tiebreaker: 'id')
            ->searchable(['path', 'last_referer'])
            ->filterable(['state' => ['open', 'fixed']])
            ->virtual(['state'])
            ->perPage(default: 50, max: 200)
            ->exportable([
                'path' => ManageText::t('not_found.path', 'العنوان'),
                'hits' => ManageText::t('not_found.hits', 'مرات الطلب'),
                'bot_hits' => ManageText::t('not_found.bot_hits', 'منها محركات البحث'),
                'first_seen_at' => ManageText::t('not_found.first_seen', 'أول مرة'),
                'last_seen_at' => ManageText::t('not_found.last_seen', 'آخر مرة'),
                'last_referer' => ManageText::t('not_found.referer', 'الصفحة التي أشارت إليه'),
                'fixed' => ManageText::t('not_found.fixed', 'تم إصلاحه'),
            ], 'pages-not-found');

        $query = DB::table('seo_not_found')->where('storefront_id', $sf)
            ->select(['id', 'path', 'hits', 'bot_hits', 'last_referer', 'first_seen_at', 'last_seen_at', 'resolved_at']);
        $state = $table->resolvedFilters()['state'] ?? 'open';
        $state === 'fixed' ? $query->whereNotNull('resolved_at') : $query->whereNull('resolved_at');

        $map = function (object $raw): array {
            $row = Row::cast($raw);

            return [
                'id' => Row::int($row, 'id'),
                'path' => Row::str($row, 'path'),
                'hits' => Row::int($row, 'hits'),
                'bot_hits' => Row::int($row, 'bot_hits'),
                'last_referer' => Row::nstr($row, 'last_referer'),
                'first_seen_at' => self::cairo(Row::nstr($row, 'first_seen_at')),
                'last_seen_at' => self::cairo(Row::nstr($row, 'last_seen_at')),
                'fixed' => Row::nstr($row, 'resolved_at') !== null,
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/NotFound/Index', [
            'storefront' => ['id' => $sf, 'code' => $storefront->code, 'name' => $storefront->name],
            'site' => StorefrontUrls::frontend($sf),
            'state' => $state,
            'open' => DB::table('seo_not_found')->where('storefront_id', $sf)->whereNull('resolved_at')->count(),
            'table' => $table->paginate($query, $map),
        ]);
    }

    /** Mark a path fixed (or open again). */
    public function update(Request $request, Storefront $storefront, int $entry): RedirectResponse
    {
        $fixed = $request->boolean('fixed');
        $row = DB::table('seo_not_found')->where('storefront_id', $storefront->id)->where('id', $entry)->first(['id', 'path', 'resolved_at']);
        abort_if($row === null, 404);
        $before = ['fixed' => Row::nstr($row, 'resolved_at') !== null];
        DB::table('seo_not_found')->where('id', $entry)->update($fixed
            ? ['resolved_at' => now(), 'resolved_by' => Auth::id()]
            : ['resolved_at' => null, 'resolved_by' => null]);
        ActivityLog::record('seo_not_found', $entry, ActivityLog::UPDATED, $before, ['fixed' => $fixed], Row::str($row, 'path'), (int) $storefront->id);

        return back()->with('success', $fixed
            ? ManageText::t('not_found.marked_fixed', 'تم تعليم العنوان كمُصلَح.')
            : ManageText::t('not_found.marked_open', 'أعيد فتح العنوان.'));
    }

    /** A stored time as Cairo time, 'Y-m-d H:i'. */
    private static function cairo(?string $db): ?string
    {
        return $db === null ? null : Carbon::parse($db, config()->string('app.timezone'))->setTimezone('Africa/Cairo')->format('Y-m-d H:i');
    }
}
