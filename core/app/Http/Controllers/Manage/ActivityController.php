<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Roles;
use App\Domain\Activity\ActivityLog;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /manage/activity — who changed what, and what it was before (wave 4D).
 *
 * ── Read-only, and admin-only ───────────────────────────────────────────────────────────────
 *
 * There is no write path here at all: no edit, no delete, not even for an administrator. A log
 * somebody can edit answers nothing, and the first question anybody asks of an audit trail is
 * whether it could have been tampered with. The absence of a route is the answer.
 *
 * Admin-only because it shows what every named person did. That is a management view, not a
 * working one — and data-entry seeing their colleagues' every edit is a different product with
 * different consequences for how the team feels about the dashboard.
 *
 * ── Three filters, because there are three questions ────────────────────────────────────────
 *
 * "What happened lately" (the default, newest first), "what did this person do" (user), and "what
 * happened to this record" (subject). The date range narrows any of them. Each maps to one of the
 * three indexes the table carries, so the screen stays fast as the log grows.
 */
final class ActivityController
{
    public function index(Request $request): Response|StreamedResponse
    {
        $table = TableQuery::for($request)
            ->sortable(['a.created_at', 'a.user_name', 'a.subject_type', 'a.action'],
                default: 'a.created_at', direction: 'desc', tiebreaker: 'a.id')
            ->searchable(['a.subject_label', 'a.user_name'])
            ->filterable([
                'user_id' => null,
                'action' => [
                    ActivityLog::CREATED, ActivityLog::UPDATED, ActivityLog::DELETED,
                    ActivityLog::RESTORED, ActivityLog::ADJUSTED, ActivityLog::GRANTED,
                    ActivityLog::REVOKED,
                ],
                'subject_type' => self::subjectTypes(),
                'from' => null,
                'to' => null,
            ])
            ->virtual(['user_id', 'subject_type', 'from', 'to'])
            /*
             * The CSV's column headings, off the SAME keys the screen's own columns use
             * (`Activity/Index.tsx`): a download whose headings disagreed with the table above it
             * would be two vocabularies for one screen.
             */
            ->exportable([
                'created_at' => ManageText::t('common.date', 'التاريخ'),
                'user_name' => ManageText::t('activity.user', 'المستخدم'),
                'action_label' => ManageText::t('activity.action', 'الإجراء'),
                'subject_type' => ManageText::t('common.type', 'النوع'),
                'subject_id' => ManageText::t('common.id', 'الرقم'),
                'subject_label' => ManageText::t('common.record', 'السجل'),
                'summary' => ManageText::t('activity.changed', 'ما تغيّر'),
            ], 'activity');

        $query = DB::table(ActivityLog::TABLE.' as a')
            ->select(['a.id', 'a.user_id', 'a.user_name', 'a.subject_type', 'a.subject_id',
                'a.subject_label', 'a.action', 'a.storefront_id', 'a.changes', 'a.created_at']);

        /*
         * ── The acting grant's storefronts, like every other list (🟡-2, 2026-09-17) ──────────
         *
         * `manage-users` opens this screen and can be granted SCOPED, so an administrator scoped to
         * Brand Fashion was reading Watchizer's entire change history — who edited which product,
         * what a price was before, which permissions were granted to whom.
         *
         * `storefront_id IS NULL` stays visible on purpose, and it is the opposite of the choice
         * the order queue made. An order always belongs to a storefront; an ACTIVITY row often does
         * not — a permission grant, a unit merge, a lookup edit are shop-wide, and a scoped operator
         * who did one of those must still see that they did. Hiding the NULLs would make a scoped
         * administrator's own actions disappear from the log the moment they were not about a
         * storefront.
         */
        self::applyStorefrontScope($query);

        self::applyFilters($query, $table->resolvedFilters());

        // Which of the referenced accounts still exist — one query, not one per row.
        $living = self::livingUsers();

        $map = function (object $raw) use ($living): array {
            $row = Row::cast($raw);
            $changes = self::changesOf(Row::nstr($row, 'changes'));
            $createdAt = Row::nstr($row, 'created_at');
            $userId = Row::nint($row, 'user_id');

            return [
                'id' => Row::int($row, 'id'),
                'user_id' => $userId,
                'user_name' => self::actorLabel($userId, Row::nstr($row, 'user_name'), $living),
                /*
                 * DISPLAY ONLY. The stored row is never touched — this flag is computed on read
                 * from `ActivityLog::HANDOVER_CUTOFF`, so the log still says exactly what it said
                 * when it was written, and the screen explains it rather than rewriting it.
                 */
                'pre_handover' => ActivityLog::isPreHandover($createdAt),
                'subject_type' => Row::str($row, 'subject_type'),
                'subject_id' => Row::nint($row, 'subject_id'),
                'subject_label' => Row::nstr($row, 'subject_label'),
                'action' => Row::str($row, 'action'),
                'action_label' => self::actionLabel(Row::str($row, 'action')),
                'storefront_id' => Row::nint($row, 'storefront_id'),
                'created_at' => Row::nstr($row, 'created_at'),
                'changes' => $changes,
                // A one-line rendering for the CSV, where a nested object would be unreadable.
                'summary' => self::summarise($changes),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/Activity/Index', [
            'table' => $table->paginate($query, $map),
            'filters' => [
                'users' => self::actors(),
                'actions' => array_map(
                    fn (string $a): array => ['value' => $a, 'label' => self::actionLabel($a)],
                    [ActivityLog::CREATED, ActivityLog::UPDATED, ActivityLog::DELETED,
                        ActivityLog::RESTORED, ActivityLog::ADJUSTED, ActivityLog::GRANTED,
                        ActivityLog::REVOKED],
                ),
                'subject_types' => array_map(
                    fn (string $t): array => ['value' => $t, 'label' => self::typeLabel($t)],
                    self::subjectTypes(),
                ),
            ],
            /*
             * Said on the screen, because a reader who does not know the boundaries will draw the
             * wrong conclusion from a gap. "No row" must not be read as "nothing happened".
             */
            /*
             * The cutoff arrives as a `:date` PLACEHOLDER rather than by concatenation: a sentence
             * glued together around a constant cannot be translated, because the two halves land in
             * different places once the word order changes.
             */
            'pre_handover_note' => ManageText::t('activity.pre_handover_note', 'الصفوف المؤرَّخة قبل :date هي نشاط اختبار من مرحلة بناء اللوحة، وليست عمل الفريق. تُركت كما هي عمدًا: سجلّ يُعاد كتابته لا قيمة له.', ['date' => ActivityLog::HANDOVER_CUTOFF]),
            'coverage' => ManageText::t('activity.coverage', 'يسجَّل: المنتجات، التصنيفات، العرض والترتيب، تعديلات المخزون اليدوية، العروض الترويجية، إعدادات الدفع، ومنح الصلاحيات. لا تُسجَّل القراءات، ولا البانرات والمقالات والقوائم المرجعية (مؤجَّلة). قيم الأسرار لا تُكتب هنا أبدًا.'),
        ]);
    }

    /**
     * Narrow the log to the acting grant's storefronts, plus the shop-wide rows (🟡-2).
     *
     * `storefront_id IS NULL` is INCLUDED, and that is the opposite of what the order queue does.
     * An order always belongs to a storefront, so a NULL there would be a gap. An activity row often
     * legitimately has none — a permission grant, a unit merge, a lookup edit are shop-wide — and
     * excluding them would make a scoped administrator's own shop-wide actions vanish from the log
     * they are reading to check what they did.
     */
    private static function applyStorefrontScope(Builder $query): void
    {
        $user = request()->user();
        if ($user === null) {
            // No session reaches this screen (the route is gated), so this is the belt behind the
            // brace — and it is the right way round: see nothing, not everything.
            $query->whereRaw('1 = 0');

            return;
        }

        $scope = app(Roles::class)->storefrontScope($user);
        if ($scope === null) {
            return;                                  // an unscoped grant sees the whole log
        }

        $ids = [];
        foreach ($scope as $id) {
            $ids[] = Coerce::int($id);
        }

        $query->where(function (Builder $scoped) use ($ids): void {
            // `[0]` for a grant that names no storefront: no row carries storefront 0, so only the
            // shop-wide rows remain — which is what "scoped to nothing" has to mean.
            $scoped->whereIn('a.storefront_id', $ids === [] ? [0] : $ids)
                ->orWhereNull('a.storefront_id');
        });
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private static function applyFilters(Builder $query, array $filters): void
    {
        $userId = Coerce::nint($filters['user_id'] ?? null);
        if ($userId !== null) {
            $query->where('a.user_id', $userId);
        }

        $action = Coerce::nstr($filters['action'] ?? null);
        if ($action !== null && $action !== '') {
            $query->where('a.action', $action);
        }

        $type = Coerce::nstr($filters['subject_type'] ?? null);
        if ($type !== null && $type !== '') {
            $query->where('a.subject_type', $type);
        }

        $from = Coerce::nstr($filters['from'] ?? null);
        if ($from !== null && $from !== '') {
            $query->where('a.created_at', '>=', $from.' 00:00:00');
        }

        $to = Coerce::nstr($filters['to'] ?? null);
        if ($to !== null && $to !== '') {
            $query->where('a.created_at', '<=', $to.' 23:59:59');
        }
    }

    /**
     * The people who appear in the log — from the LOG, not from the user list.
     *
     * A filter offering somebody who has never done anything is a control that always returns
     * nothing, and the point of this list is "whose work can I look at".
     *
     * @return list<array{value: string, label: string}>
     */
    private static function actors(): array
    {
        $out = [];
        foreach (
            DB::table(ActivityLog::TABLE)->whereNotNull('user_id')
                ->select(['user_id', 'user_name'])->distinct()->orderBy('user_name')->get() as $raw
        ) {
            $row = Row::cast($raw);
            $out[] = [
                'value' => (string) Row::int($row, 'user_id'),
                'label' => Row::nstr($row, 'user_name') ?? ('#'.Row::int($row, 'user_id')),
            ];
        }

        return $out;
    }

    /**
     * Subject types, as an allow-list for the filter.
     *
     * Hard-coded rather than `SELECT DISTINCT`: the allow-list is what `resolvedFilters()` validates
     * against, and deriving it from the data would mean a type nobody has touched yet cannot be
     * filtered for — including, on a fresh install, all of them.
     *
     * @return list<string>
     */
    private static function subjectTypes(): array
    {
        return [
            'catalog_products', 'catalog_product_variants', 'storefront_categories',
            'storefront_product', 'promotion_rules', 'storefront_payment_providers',
            'storefront_payment_methods', 'core_user_roles', 'shipping_cities',
        ];
    }

    /** @return array<string, mixed> */
    private static function changesOf(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? Coerce::arr($decoded) : [];
    }

    /** @param array<string, mixed> $changes */
    private static function summarise(array $changes): string
    {
        $parts = [];
        foreach ($changes as $field => $change) {
            $from = Coerce::arr($change)['from'] ?? null;
            $to = Coerce::arr($change)['to'] ?? null;
            $parts[] = $field.': '.self::scalar($from).' ← '.self::scalar($to);
        }

        return implode(' | ', $parts);
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return is_scalar($value) ? (string) $value : '…';
    }

    /**
     * The LABEL for a stored action.
     *
     * The stored `action` column is vocabulary — `created`, `granted` — and nothing here touches it;
     * this is only what the screen calls it. `default` deliberately returns the raw value: a row
     * written by a future writer should read as the word it stored rather than as a blank.
     */
    private static function actionLabel(string $action): string
    {
        return match ($action) {
            ActivityLog::CREATED => ManageText::t('activity.action_created', 'إنشاء'),
            ActivityLog::UPDATED => ManageText::t('activity.action_updated', 'تعديل'),
            ActivityLog::DELETED => ManageText::t('activity.action_deleted', 'حذف'),
            ActivityLog::RESTORED => ManageText::t('activity.action_restored', 'استرجاع'),
            ActivityLog::ADJUSTED => ManageText::t('activity.action_adjusted', 'تعديل مخزون'),
            ActivityLog::GRANTED => ManageText::t('activity.action_granted', 'منح صلاحية'),
            ActivityLog::REVOKED => ManageText::t('activity.action_revoked', 'سحب صلاحية'),
            default => $action,
        };
    }

    /**
     * The LABEL for a stored subject type.
     *
     * The match ARMS are table names — the values the writers put in `subject_type` — and they stay
     * exactly as they are. Only the right-hand side is operator text. A type with no label falls
     * through to the table name, which is honest: better a reader sees `catalog_specs` than nothing.
     */
    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'catalog_products' => ManageText::t('banners.target_product', 'منتج'),
            'catalog_product_variants' => ManageText::t('activity.type_variant', 'متغيّر'),
            'storefront_categories' => ManageText::t('common.category', 'تصنيف'),
            'storefront_product' => ManageText::t('activity.type_placement', 'عرض وترتيب'),
            'promotion_rules' => ManageText::t('activity.type_promotion_rule', 'قاعدة ترويجية'),
            'storefront_payment_providers' => ManageText::t('activity.type_payment_provider', 'عقد دفع'),
            'storefront_payment_methods' => ManageText::t('activity.type_payment_method', 'طريقة دفع'),
            'core_user_roles' => ManageText::t('activity.type_permission', 'صلاحية'),
            'shipping_cities' => ManageText::t('activity.type_shipping_price', 'سعر شحن'),
            default => $type,
        };
    }

    /**
     * Accounts that still exist, as id => true.
     *
     * Asked once per page. The alternative — a join — would DROP rows whose user is gone, which is
     * the opposite of what a log is for: the entry about a deleted administrator's last act is
     * precisely the one somebody will come looking for.
     *
     * @return array<int, true>
     */
    private static function livingUsers(): array
    {
        $out = [];
        foreach (DB::table('users')->pluck('id') as $id) {
            if (is_numeric($id)) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /**
     * Who to show for this row.
     *
     * Three cases, and they are genuinely different facts:
     *
     *  • **no user at all** (`user_id IS NULL`) — a CLI run, a scheduled job, an importer. The log
     *    says nobody was logged in, and "النظام" says exactly that. It is NOT relabelled as a
     *    person: attributing an unattributed act to a named human would be the log asserting
     *    something it never recorded, which is the one thing this table must never do.
     *  • **a user who is gone** — the name captured at write time still reads correctly, and the
     *    screen marks the account as deleted so nobody hunts for it in the users list.
     *  • **a user who is still here** — their name, as it was then.
     *
     * @param  array<int, true>  $living
     */
    private static function actorLabel(?int $userId, ?string $capturedName, array $living): string
    {
        if ($userId === null) {
            return ManageText::t('common.system', 'النظام');
        }

        $name = $capturedName ?? ('#'.$userId);

        // The captured name is DATA and goes in as a `:name` placeholder, so the parenthetical can
        // move to wherever the language puts it instead of being glued to the end of the name.
        return isset($living[$userId])
            ? $name
            : ManageText::t('activity.actor_deleted', ':name (حساب محذوف)', ['name' => $name]);
    }
}
