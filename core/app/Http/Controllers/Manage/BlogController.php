<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\BlogWriter;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\FullReplace;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /manage/blogs — the articles screen (item 14, developer 2026-09-18).
 *
 * ── What this is, and what it deliberately is not ────────────────────────────────────────────
 *
 * The developer scoped it: *"list, create, edit, publish/unpublish, with Arabic and English fields
 * and the SEO fields. NO image gallery for now — a single cover image is enough."*
 *
 * So: one list, one form, one publish toggle, one cover. No author, no categories, no tags, no
 * comments, no scheduling. Each of those is a decision somebody would have to make and nobody has.
 *
 * ── Core-owned, not legacy ───────────────────────────────────────────────────────────────────
 *
 * The legacy `blogs` tables are empty and carry no slug, no published flag and no SEO fields, and
 * core may not write legacy tables anyway (AGENTS §3; the `legacy` connection runs read-only). So
 * articles live in `core_blogs` + `core_blog_translations`, which are DASHBOARD-OWNED and therefore
 * survive switch night's rebuild — see `CoreChecksumCommand::DASHBOARD_TABLES`.
 *
 * ── The storefront does not serve these yet ──────────────────────────────────────────────────
 *
 * This is the dashboard half only, which is what was asked for. Nothing in `App\Storefront` reads
 * `core_blogs`, so an article published here is written, stored and visible to the team, and no
 * customer can reach it until somebody builds the read endpoint and the page. That is stated on the
 * screen rather than left for a confused operator to discover.
 */
final class BlogController
{
    public function __construct(private readonly BlogWriter $blogs) {}

    public function index(): Response
    {
        return Inertia::render('Manage/Blogs/Index', [
            'rows' => self::rows(),
            /*
             * The one thing this screen owes an operator that it cannot do anything about: an
             * article published here does not yet appear on the storefront, because no read
             * endpoint serves it. Said on the screen so nobody publishes one and then goes looking
             * for it on the site.
             */
            'storefront_note' => ManageText::t(
                'blogs.not_served_yet',
                'المقالات تُكتب وتُحفظ هنا، لكن الموقع لا يعرضها بعد: صفحة المقالات على المتجر لم تُبنَ. النشر هنا يعني أن المقال جاهز، لا أنه ظاهر للعملاء.',
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Manage/Blogs/Form', [
            'blog' => null,
        ]);
    }

    public function edit(int $blog): Response
    {
        $row = self::one($blog);
        abort_if($row === null, 404);

        return Inertia::render('Manage/Blogs/Form', [
            'blog' => $row,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate(BlogWriter::rules()));
        $id = $this->blogs->save(null, $data);

        ActivityLog::record(
            'core_blogs', $id, ActivityLog::CREATED,
            [], self::logFields($id),
            label: self::label($id),
        );

        return redirect()
            ->route('manage.blogs.edit', ['blog' => $id])
            ->with('status', ManageText::t('blogs.created', 'تم إنشاء المقال.'));
    }

    public function update(Request $request, int $blog): RedirectResponse
    {
        abort_if(self::one($blog) === null, 404);

        // Replaces the article: a save that omits the English body clears it, exactly as the
        // product form's contract works ({@see FullReplace}).
        FullReplace::assert($request, ManageText::t('blogs.record', 'المقال'), 'title.ar');

        $before = self::logFields($blog);
        $data = Coerce::arr($request->validate(BlogWriter::rules($blog)));
        $this->blogs->save($blog, $data);

        ActivityLog::record(
            'core_blogs', $blog, ActivityLog::UPDATED,
            $before, self::logFields($blog),
            label: self::label($blog),
        );

        return back()->with('status', ManageText::t('blogs.saved', 'تم حفظ المقال.'));
    }

    /** Publish or unpublish from the LIST, without sending the whole article back. */
    public function publish(Request $request, int $blog): RedirectResponse
    {
        abort_if(self::one($blog) === null, 404);

        $data = Coerce::arr($request->validate(['is_published' => ['required', 'boolean']]));
        $before = self::logFields($blog);

        $this->blogs->setPublished($blog, (bool) $data['is_published']);

        ActivityLog::record(
            'core_blogs', $blog, ActivityLog::UPDATED,
            $before, self::logFields($blog),
            label: self::label($blog),
        );

        return back()->with('status', (bool) $data['is_published']
            ? ManageText::t('blogs.published', 'تم نشر المقال.')
            : ManageText::t('blogs.unpublished', 'تم إلغاء نشر المقال.'));
    }

    public function destroy(int $blog): RedirectResponse
    {
        abort_if(self::one($blog) === null, 404);

        $before = self::logFields($blog);
        $label = self::label($blog);
        $this->blogs->delete($blog);

        ActivityLog::record('core_blogs', $blog, ActivityLog::DELETED, $before, [], label: $label);

        return redirect()
            ->route('manage.blogs.index')
            ->with('status', ManageText::t('blogs.deleted', 'تم حذف المقال.'));
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────

    /**
     * The list: newest first, with DRAFTS at the top.
     *
     * A draft is the row somebody is coming back to, so it is the row that should be in front of
     * them. A published article is finished and only wants finding.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        $out = [];
        foreach (
            DB::table('core_blogs as b')
                ->leftJoin('core_blog_translations as ar', function (JoinClause $join): void {
                    $join->on('ar.blog_id', '=', 'b.id')->where('ar.locale', '=', 'ar');
                })
                ->leftJoin('core_blog_translations as en', function (JoinClause $join): void {
                    $join->on('en.blog_id', '=', 'b.id')->where('en.locale', '=', 'en');
                })
                ->orderByRaw('b.published_at IS NULL DESC')
                ->orderByDesc('b.published_at')
                ->orderByDesc('b.id')
                ->get([
                    'b.id', 'b.slug', 'b.cover_path', 'b.published_at', 'b.updated_at',
                    'ar.title as title_ar', 'en.title as title_en',
                    'ar.body as body_ar', 'en.body as body_en',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $cover = Row::nstr($row, 'cover_path');
            $publishedAt = Row::nstr($row, 'published_at');

            $out[] = [
                'id' => Row::int($row, 'id'),
                'slug' => Row::str($row, 'slug'),
                'title' => [
                    'ar' => Row::nstr($row, 'title_ar') ?? '',
                    'en' => Row::nstr($row, 'title_en') ?? '',
                ],
                'cover' => $cover === null ? null : ImageUrl::src($cover),
                'is_published' => $publishedAt !== null,
                'published_at' => $publishedAt,
                'updated_at' => Row::nstr($row, 'updated_at'),
                /*
                 * Whether it COULD be published — the same rule the writer enforces, so the list's
                 * toggle is disabled rather than clicked and refused. The refusal still stands on
                 * the server; this only stops the operator finding out by being told no.
                 */
                'has_body' => (Row::nstr($row, 'body_ar') ?? '') !== '' || (Row::nstr($row, 'body_en') ?? '') !== '',
            ];
        }

        return $out;
    }

    /**
     * One article, in the shape the form edits.
     *
     * @return array<string, mixed>|null
     */
    private static function one(int $id): ?array
    {
        $row = DB::table('core_blogs')->where('id', $id)->first();
        if (! is_object($row)) {
            return null;
        }
        $blog = Row::cast($row);

        $titles = ['ar' => '', 'en' => ''];
        $bodies = ['ar' => '', 'en' => ''];
        $metaTitles = ['ar' => '', 'en' => ''];
        $metaDescriptions = ['ar' => '', 'en' => ''];

        foreach (DB::table('core_blog_translations')->where('blog_id', $id)->get() as $raw) {
            $translation = Row::cast($raw);
            $locale = Row::str($translation, 'locale');
            if (! in_array($locale, BlogWriter::LOCALES, true)) {
                continue;
            }
            $titles[$locale] = Row::nstr($translation, 'title') ?? '';
            $bodies[$locale] = Row::nstr($translation, 'body') ?? '';
            $metaTitles[$locale] = Row::nstr($translation, 'meta_title') ?? '';
            $metaDescriptions[$locale] = Row::nstr($translation, 'meta_description') ?? '';
        }

        $cover = Row::nstr($blog, 'cover_path');

        return [
            'id' => Row::int($blog, 'id'),
            'slug' => Row::str($blog, 'slug'),
            'cover_path' => $cover,
            'cover_url' => $cover === null ? null : ImageUrl::src($cover),
            'is_published' => Row::nstr($blog, 'published_at') !== null,
            'published_at' => Row::nstr($blog, 'published_at'),
            'title' => $titles,
            'body' => $bodies,
            'meta_title' => $metaTitles,
            'meta_description' => $metaDescriptions,
        ];
    }

    /**
     * What the activity log records about an article.
     *
     * The BODY is deliberately not in here. An article is long-form text and two revisions of it
     * would fill the log with a diff nobody reads; the log answers "who changed this article, and
     * did they publish it", which is what somebody actually asks.
     *
     * @return array<string, mixed>
     */
    private static function logFields(int $id): array
    {
        $row = DB::table('core_blogs')->where('id', $id)->first(['slug', 'published_at', 'cover_path']);
        if (! is_object($row)) {
            return [];
        }
        $blog = Row::cast($row);

        return [
            'slug' => Row::str($blog, 'slug'),
            'published_at' => Row::nstr($blog, 'published_at'),
            'cover_path' => Row::nstr($blog, 'cover_path'),
        ];
    }

    /** The Arabic title, which is what the log's reader recognises. */
    private static function label(int $id): ?string
    {
        $title = DB::table('core_blog_translations')
            ->where('blog_id', $id)->where('locale', 'ar')->value('title');

        return Coerce::nstr($title);
    }
}
