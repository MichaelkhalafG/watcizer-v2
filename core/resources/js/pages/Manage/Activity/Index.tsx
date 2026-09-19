import { DataTable, type Column } from '@/components/table/DataTable';
import { DateRangeFilter } from '@/components/table/DateRangeFilter';
import { Badge } from '@/components/ui/badge';
import { Num } from '@/components/ui/bidi';
import { Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { changedFieldLabel } from '@/lib/labels';
import type { TablePayload } from '@/types';

/**
 * Activity log — who changed what, and what it was before (wave 4D).
 *
 * ── Read-only by construction ───────────────────────────────────────────────────────────────
 *
 * No edit control, no delete, no bulk action. There is no route behind them either: a log somebody
 * can change answers nothing, and the first question anyone asks of an audit trail is whether it
 * could have been tampered with.
 *
 * ── The diff IS the row ─────────────────────────────────────────────────────────────────────
 *
 * Every other screen here shows a record's current state; this one shows a TRANSITION. So the
 * changed fields are rendered inline as `field: old ← new` rather than hidden behind a detail
 * click — "who changed the price, and from what" is one question, and making the second half cost
 * a page load is what stops people using an audit trail at all.
 *
 * Secret values never arrive: the server writes a marker instead, so there is nothing here that
 * could render one.
 */

interface Change {
    from: string | number | boolean | null;
    to: string | number | boolean | null;
}

interface Entry {
    id: number;
    user_id: number | null;
    user_name: string;
    subject_type: string;
    subject_id: number | null;
    /** The same word the filter shows — never the table name (item 10). */
    subject_type_label: string;
    subject_label: string | null;
    action: string;
    action_label: string;
    storefront_id: number | null;
    created_at: string | null;
    /** Computed on READ from the cutoff — the stored row is never touched. */
    pre_handover: boolean;
    changes: Record<string, Change>;
    summary: string;
}

interface Props {
    table: TablePayload<Entry>;
    filters: {
        users: { value: string; label: string }[];
        actions: { value: string; label: string }[];
        subject_types: { value: string; label: string }[];
    };
    coverage: string;
    pre_handover_note: string;
}

/** Colour by consequence, not by verb: a deletion and a permission grant deserve the eye. */
function actionTone(action: string): 'default' | 'destructive' | 'warning' | 'neutral' | 'success' {
    switch (action) {
        case 'deleted':
        case 'revoked':
            return 'destructive';
        case 'granted':
            return 'warning';
        case 'created':
        case 'restored':
            return 'success';
        default:
            return 'neutral';
    }
}

function Value({ value }: { value: Change['from'] }) {
    const t = useT();

    if (value === null || value === '') {
        return <span className="text-muted-foreground">—</span>;
    }
    if (typeof value === 'boolean') {
        return <span>{value ? t('common.yes', 'نعم') : t('common.no', 'لا')}</span>;
    }
    if (typeof value === 'number') {
        return <Num>{value}</Num>;
    }
    return <span className="break-all">{value}</span>;
}

export default function ActivityIndex({ table, filters, coverage, pre_handover_note: preHandoverNote }: Props) {
    const t = useT();
    const columns: Column<Entry>[] = [
        {
            key: 'created_at',
            header: t('common.date', 'التاريخ'),
            sortable: true,
            cell: (row) => <Num>{row.created_at ?? '—'}</Num>,
        },
        {
            key: 'user_name',
            header: t('activity.user', 'المستخدم'),
            sortable: true,
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate">{row.user_name}</div>
                    {row.pre_handover && (
                        // Explained, not hidden and not rewritten: these rows are real, and they
                        // are from the build rather than from the shop.
                        <Badge variant="neutral" className="mt-0.5">
                            {t('activity.pre_handover', 'نشاط ما قبل التسليم')}
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            key: 'action',
            header: t('activity.action', 'الإجراء'),
            sortable: true,
            cell: (row) => <Badge variant={actionTone(row.action)}>{row.action_label}</Badge>,
        },
        /*
         * `ما تغيّر` sits DIRECTLY beside `الإجراء`, and `السجل` is capped.
         *
         * Read in that order the row is a sentence — who, did what, from what to what — and the
         * two columns an auditor actually reads are adjacent. The previous order put the audit
         * trail's entire payload last, behind an unbounded `السجل` cell that took every pixel of
         * slack: measured at a 1,740 px window, `السجل` was 1,257 px wide to hold `000009` and
         * `ما تغيّر` began at x = -376, i.e. wholly off-screen. There IS an `overflow-x-auto`
         * wrapper, but in RTL `scrollLeft: 0` is the RIGHT edge, so the page opened on the
         * columns nobody needed and the operator concluded nothing had been recorded.
         */
        {
            key: 'changes',
            header: t('activity.changed', 'ما تغيّر'),
            cell: (row) => {
                const entries = Object.entries(row.changes);
                if (entries.length === 0) {
                    return <span className="text-muted-foreground">—</span>;
                }
                return (
                    <ul className="space-y-0.5 text-xs">
                        {entries.map(([field, change]) => (
                            <li key={field} className="flex flex-wrap items-baseline gap-1">
                                {/* Item 10: a column name is not a field name. `is_primary` and
                                    `type_stock` mean nothing to the person reading their own audit
                                    trail; an unmapped column still shows verbatim, because a new
                                    one is something to notice. */}
                                <span className="font-medium">{changedFieldLabel(t, field)}</span>
                                <Value value={change.from} />
                                <span className="text-muted-foreground">←</span>
                                <Value value={change.to} />
                            </li>
                        ))}
                    </ul>
                );
            },
        },
        {
            key: 'subject_label',
            header: t('common.record', 'السجل'),
            // Capped so it can never take the slack again. The full value stays reachable as the
            // cell's `title`, which is the right place for an overflow of a value the reader can
            // already see most of — unlike J-8, where a `title` hid a RULE nothing else stated.
            className: 'max-w-[20rem]',
            cell: (row) => (
                <div className="min-w-0 max-w-[20rem]">
                    <div className="truncate" title={row.subject_label ?? undefined}>
                        {row.subject_label ?? '—'}
                    </div>
                    <div className="truncate text-xs text-muted-foreground">
                        {/* Item 10: the word, which the filter above has always used. */}
                        {row.subject_type_label}
                        {row.subject_id !== null && (
                            <>
                                {' · '}
                                <Num>{row.subject_id}</Num>
                            </>
                        )}
                    </div>
                </div>
            ),
        },
    ];

    return (
        <ManageLayout title={t('activity.title', 'سجل النشاط')}>
            <div className="mb-3">
                <h1 className="text-lg font-semibold">{t('activity.title', 'سجل النشاط')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    {t('activity.subtitle', 'من غيّر ماذا، ومتى، وما كانت القيمة قبل التغيير.')}
                </p>
            </div>

            {/* The boundaries, on the screen: a gap must not be read as "nothing happened". */}
            <p className="mb-2 rounded-md border border-dashed p-3 text-xs leading-relaxed text-muted-foreground">
                {coverage}
            </p>
            <p className="mb-4 rounded-md border border-dashed p-3 text-xs leading-relaxed text-muted-foreground">
                {preHandoverNote}
            </p>

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t('activity.search_placeholder', 'اسم السجل أو المستخدم…')}
                filters={(setFilter, current) => (
                    <>
                        {(
                            [
                                ['user_id', t('activity.all_users', 'كل المستخدمين'), filters.users],
                                ['action', t('activity.all_actions', 'كل الإجراءات'), filters.actions],
                                ['subject_type', t('activity.all_types', 'كل الأنواع'), filters.subject_types],
                            ] as const
                        ).map(([key, empty, options]) => (
                            <Select
                                key={key}
                                className="w-full sm:w-48"
                                aria-label={empty}
                                value={current[key] ?? ''}
                                onChange={(event) => setFilter(key, event.target.value || null)}
                            >
                                <option value="">{empty}</option>
                                {options.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        ))}

                        {/* The date range narrows any of the three questions above. One control
                            rather than two bare boxes the filter row could wrap between (§2.8). */}
                        <DateRangeFilter
                            from={current.from ?? null}
                            to={current.to ?? null}
                            onChange={(from, to) => {
                                setFilter('from', from);
                                setFilter('to', to);
                            }}
                        />
                    </>
                )}
            />
        </ManageLayout>
    );
}
