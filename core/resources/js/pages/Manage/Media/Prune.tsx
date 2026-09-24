import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Ltr, Num } from '@/components/ui/bidi';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { mediaTypeLabel } from '@/lib/labels';

/**
 * Media cleanup (wave 4D, task C4) — what `media:prune` sees, with a button.
 *
 * ── The screen is mostly a REFUSAL, and that is the design ──────────────────────────────────
 *
 * This page removes files that the LIVE legacy storefront still serves from a shared directory.
 * The command it wraps calls itself "the most dangerous command in the repo" and means it, so the
 * screen leads with whether deleting is allowed at all — `refusal` comes from the same
 * `MediaAudit::refusal()` the command calls — and only then shows the button.
 *
 * The confirmation is a TYPED NUMBER, not a modal: the count changes with the scan, so it cannot
 * become muscle memory, and the server re-scans and re-checks it, so a tree that changed between
 * the page load and the click deletes nothing.
 */

interface TypeRow {
    type: string;
    folder: string;
    masters: number;
    referenced: number;
    young: number;
    orphans: number;
    bytes: number;
}

interface SampleRow {
    type: string;
    folder: string;
    master: string;
    renditions: number;
    bytes: number;
}

interface Props {
    types: TypeRow[];
    referenced: number;
    matched: number;
    orphan_masters: number;
    orphan_files: number;
    bytes: number;
    human_bytes: string;
    sample: SampleRow[];
    sample_is_partial: boolean;
    warnings: string[];
    refusal: string | null;
    coverage_warning: string | null;
    min_age_days: number;
    errors?: Record<string, string>;
}

export default function MediaPrune({
    types,
    referenced,
    matched,
    orphan_masters,
    orphan_files,
    human_bytes,
    sample,
    sample_is_partial,
    warnings,
    refusal,
    coverage_warning,
    min_age_days,
}: Props) {
    const t = useT();
    const [confirm, setConfirm] = useState('');

    const canDelete = refusal === null && orphan_files > 0;

    return (
        <ManageLayout
            title={t('media.title', 'تنظيف الوسائط')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('media.title', 'تنظيف الوسائط') },
            ]}
        >
            <div className="space-y-4">
                <div className="rounded-lg border bg-card p-4 text-sm leading-relaxed">
                    <p>
                        {/* One key for the whole sentence: the emphasised clause is a `:unreferenced`
                            placeholder rather than a second key, so the translator can move it. */}
                        <Around
                            text={t(
                                'media.intro',
                                'شجرة الصور مشتركة بين النظام القديم والجديد. هذه الشاشة تبحث عن الملفات التي :unreferenced في أي من النظامين — بقايا رفعٍ لم يُكمل — ولا تلمس أي ملف مستخدم.',
                            )}
                            placeholder=":unreferenced"
                        >
                            <strong>{t('media.intro_unreferenced', 'لا يشير إليها أي صف')}</strong>
                        </Around>
                    </p>
                    <p className="mt-2 text-muted-foreground">
                        {t(
                            'media.age_note',
                            'النسخ المصغّرة تُحسب مع أصلها، ولا يُحذف ملف أحدث من :days أيام، حتى لا يُمسّ رفعٌ قيد الاستخدام الآن.',
                            { days: min_age_days },
                        )}
                    </p>
                </div>

                {warnings.length > 0 ? (
                    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm" role="alert">
                        <div className="font-medium">{t('media.sources_unreadable', 'تعذّرت قراءة بعض المصادر:')}</div>
                        <ul className="mt-1 list-inside list-disc">
                            <Ltr>
                                {warnings.map((warning) => (
                                    <li key={warning}>{warning}</li>
                                ))}
                            </Ltr>
                        </ul>
                    </div>
                ) : null}

                {coverage_warning !== null ? (
                    <div className="rounded-lg border border-amber-300/60 bg-amber-50/60 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/20" role="note">
                        {coverage_warning}
                    </div>
                ) : null}

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border bg-card p-4">
                        <div className="text-xs text-muted-foreground">{t('media.referenced_files', 'ملفات مُشار إليها')}</div>
                        <div className="text-2xl font-semibold">
                            <Num>{referenced}</Num>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {t('media.matched_in_tree', 'منها :count موجودة في هذه الشجرة', { count: matched })}
                        </div>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <div className="text-xs text-muted-foreground">{t('media.orphan_files', 'ملفات يتيمة')}</div>
                        <div className="text-2xl font-semibold">
                            <Num>{orphan_files}</Num>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {t('media.orphan_masters', ':count صورة أصلية مع نسخها', { count: orphan_masters })}
                        </div>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <div className="text-xs text-muted-foreground">{t('media.disk_space', 'المساحة')}</div>
                        <div className="text-2xl font-semibold">
                            <Num>{human_bytes}</Num>
                        </div>
                    </div>
                </div>

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader className="bg-muted/50">
                            <TableRow>
                                <TableHead>{t('media.image_type', 'النوع')}</TableHead>
                                <TableHead>{t('media.folder', 'المجلد')}</TableHead>
                                <TableHead>{t('media.on_disk', 'على القرص')}</TableHead>
                                <TableHead>{t('media.in_use', 'مستخدمة')}</TableHead>
                                <TableHead>{t('media.too_recent', 'حديثة')}</TableHead>
                                <TableHead>{t('media.orphans', 'يتيمة')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.map((row) => (
                                <TableRow key={row.type}>
                                    <TableCell>
                                        {mediaTypeLabel(t, row.type)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        <Ltr>{row.folder}</Ltr>
                                    </TableCell>
                                    <TableCell>
                                        <Num>{row.masters}</Num>
                                    </TableCell>
                                    <TableCell>
                                        <Num>{row.referenced}</Num>
                                    </TableCell>
                                    <TableCell>
                                        <Num>{row.young}</Num>
                                    </TableCell>
                                    <TableCell>
                                        {row.orphans === 0 ? (
                                            <span className="text-muted-foreground">—</span>
                                        ) : (
                                            <Badge variant="warning">
                                                <Num>{row.orphans}</Num>
                                            </Badge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {sample.length > 0 ? (
                    <details className="rounded-lg border bg-card p-4 text-sm">
                        <summary className="cursor-pointer font-medium">
                            {t('media.sample_title', 'عيّنة من الملفات اليتيمة (:count)', {
                                // The "+" says the scan stopped early; it belongs to the number.
                                count: `${sample.length}${sample_is_partial ? '+' : ''}`,
                            })}
                        </summary>
                        <ul className="mt-2 space-y-1 text-xs text-muted-foreground">
                            <Ltr>
                                {sample.map((row) => (
                                    <li key={`${row.folder}/${row.master}`}>
                                        {row.folder}/{row.master}
                                        {row.renditions > 0 ? ` (+${row.renditions})` : ''}
                                    </li>
                                ))}
                            </Ltr>
                        </ul>
                    </details>
                ) : null}

                {refusal !== null ? (
                    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm leading-relaxed" role="alert">
                        <div className="font-medium">{t('media.delete_unavailable', 'الحذف غير متاح الآن')}</div>
                        <p className="mt-1">{refusal}</p>
                    </div>
                ) : orphan_files === 0 ? (
                    <div className="rounded-lg border bg-card p-4 text-sm">
                        {t('media.no_orphans', 'لا توجد ملفات يتيمة. الشجرة نظيفة.')}
                    </div>
                ) : (
                    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
                        <div className="font-medium">
                            {t('media.delete_permanently', 'حذف :count ملف نهائيًا', { count: orphan_files })}
                        </div>
                        <p className="mt-1 text-sm leading-relaxed">
                            {/* The number is typed back in, so it stays a bold LTR run inside the
                                sentence rather than being flattened into the translated text. */}
                            <Around
                                text={t(
                                    'media.delete_confirm_hint',
                                    'لا يمكن التراجع. اكتب العدد :count للتأكيد — لو تغيّرت الشجرة منذ فتح الصفحة فلن يُحذف شيء.',
                                )}
                                placeholder=":count"
                            >
                                <strong dir="ltr">{orphan_files}</strong>
                            </Around>
                        </p>
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <Input
                                aria-label={t('media.confirm_count', 'تأكيد العدد')}
                                className="w-32"
                                dir="ltr"
                                inputMode="numeric"
                                value={confirm}
                                onChange={(event) => setConfirm(event.target.value)}
                            />
                            <Button
                                variant="destructive"
                                disabled={!canDelete || confirm !== String(orphan_files)}
                                onClick={() =>
                                    router.delete('/manage/media/prune', {
                                        data: { confirm },
                                        preserveScroll: true,
                                        onFinish: () => setConfirm(''),
                                    })
                                }
                            >
                                {t('media.delete_orphans', 'حذف الملفات اليتيمة')}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </ManageLayout>
    );
}

/**
 * Render `text` with `children` substituted for its `:placeholder`.
 *
 * A sentence that wraps one fragment in markup would otherwise have to be split into two keys, and
 * a translator handed two halves cannot reorder them — which is exactly what Arabic → English
 * needs to do. So the key stays ONE sentence carrying a Laravel-style `:name` placeholder, and the
 * substitution happens here instead of in `t()`, because the value is an element and not a string.
 */
function Around({ text, placeholder, children }: { text: string; placeholder: string; children: ReactNode }) {
    const [before, ...rest] = text.split(placeholder);

    return (
        <>
            {before}
            {rest.length === 0 ? null : children}
            {rest.join(placeholder)}
        </>
    );
}
