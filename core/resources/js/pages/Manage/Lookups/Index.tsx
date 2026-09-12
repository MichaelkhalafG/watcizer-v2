import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ImageField, type StoredImage } from '@/components/form/ImageField';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { PreSwitchState, SharedProps } from '@/types';

type ExtraField = { label: string; type: 'string' | 'integer' | 'boolean' | 'hex' | 'slug' | 'image'; required?: boolean; default?: unknown; media_type?: string };

interface Row {
    id: number;
    name: { ar: string; en: string };
    extra: Record<string, string | number | boolean | null>;
    uses: number;
}

interface Props {
    list: { key: string; label: string; extra: Record<string, ExtraField>; usage_tables: string[] };
    lists: Array<{ key: string; label: string; url: string }>;
    rows: Row[];
    pre_switch: PreSwitchState;
}

/**
 * /manage/lookups/{list} — brands and the eleven lookup lists the product form consumes
 * (scope item 6).
 *
 * One screen for twelve lists, because they are one shape (`config('catalog.lookups')` is the
 * whole definition). The tabs across the top are the lists; the columns come from the list's own
 * `extra` declaration, so adding a colour code or a logo to a list is a config line and not a new
 * screen.
 *
 * ── The usage count is the point ─────────────────────────────────────────────────────────────
 *
 * Every reference from a product or a variant is a `RESTRICT` foreign key (M1), so deleting a
 * colour that 200 products use fails at the DATABASE with a 1451 — which reaches a team member as
 * a 500 page. The count next to each row, and the disabled delete button that names it, is the
 * message. The foreign key stays the mechanism: this screen is not what makes it safe.
 *
 * ── Arabic is required, English is optional and really optional ──────────────────────────────
 *
 * Fallback is off (AGENTS §2.17), so an empty English name is DELETED rather than stored blank: a
 * blank row and a missing row look the same to a reader and completely different to the storefront.
 */
export default function LookupsIndex({ list, lists, rows, pre_switch }: Props) {
    const { errors } = usePage<SharedProps>().props;
    const [draft, setDraft] = useState<{ ar: string; en: string; extra: Record<string, string | boolean> }>({ ar: '', en: '', extra: {} });
    const [edits, setEdits] = useState<Record<number, { ar?: string; en?: string; extra?: Record<string, string | boolean> }>>({});

    const columns = Object.entries(list.extra);
    const base = `/manage/lookups/${list.key}`;

    const extraPayload = (values: Record<string, string | boolean>): Record<string, string | boolean | null> => {
        const out: Record<string, string | boolean | null> = {};
        for (const [column, field] of columns) {
            const value = values[column];
            out[column] = field.type === 'boolean' ? value === true : (typeof value === 'string' ? value : null);
        }

        return out;
    };

    const rowValue = (row: Row, column: string): string | boolean => {
        const patched = edits[row.id]?.extra?.[column];
        if (patched !== undefined) {
            return patched;
        }
        const value = row.extra[column];
        if (typeof value === 'boolean') {
            return value;
        }

        return value === null || value === undefined ? '' : String(value);
    };

    const dirty = (id: number) => {
        const patch = edits[id];

        return patch !== undefined && (patch.ar !== undefined || patch.en !== undefined || Object.keys(patch.extra ?? {}).length > 0);
    };

    const saveRow = (row: Row) => {
        const patch = edits[row.id] ?? {};
        const extra: Record<string, string | boolean> = {};
        for (const [column] of columns) {
            extra[column] = rowValue(row, column);
        }

        router.put(
            `${base}/${row.id}`,
            {
                // This endpoint REPLACES the row, extras included, so the screen sends every
                // column it knows about and declares the payload complete. Without `_complete`
                // the server refuses: a caller that omits `extra.hex` would clear the colour.
                _complete: 1,
                name: { ar: patch.ar ?? row.name.ar, en: patch.en ?? row.name.en },
                extra: extraPayload(extra),
            },
            { preserveScroll: true, onSuccess: () => setEdits((current) => ({ ...current, [row.id]: {} })) },
        );
    };

    return (
        <ManageLayout
            title={list.label}
            crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'الماركات والقوائم' }, { label: list.label }]}
        >
            {/* The lists, as tabs. One screen, twelve datasets. */}
            <nav aria-label="القوائم المرجعية" className="flex flex-wrap gap-1.5">
                {lists.map((item) => (
                    <Link
                        key={item.key}
                        href={item.url}
                        className={cn(
                            'rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                            item.key === list.key ? 'border-transparent bg-brand-muted text-brand-strong' : 'hover:bg-muted',
                        )}
                        aria-current={item.key === list.key ? 'page' : undefined}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>

            {errors.delete ? (
                <Alert tone="error" title="تعذّر الحذف">
                    {errors.delete}
                </Alert>
            ) : null}

            <Card>
                <CardHeader className="gap-1">
                    <CardTitle>{list.label}</CardTitle>
                    <p className="text-xs text-muted-foreground">
                        عمود «الاستخدام» يحسب الإشارات من{' '}
                        <span dir="ltr" className="font-mono">
                            {list.usage_tables.join(' · ')}
                        </span>
                        . الحذف مرفوض ما دام العدد أكبر من صفر — المفاتيح الأجنبية ترفضه أصلًا، وهذه هي الرسالة قبل أن تصير خطأ.
                    </p>
                </CardHeader>

                <CardContent className="space-y-4">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-14">#</TableHead>
                                    <TableHead>الاسم (عربي)</TableHead>
                                    <TableHead>Name (English)</TableHead>
                                    {columns.map(([column, field]) => (
                                        <TableHead key={column}>{field.label}</TableHead>
                                    ))}
                                    <TableHead>الاستخدام</TableHead>
                                    <TableHead className="text-end">إجراءات</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-mono text-xs" dir="ltr">
                                            {row.id}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="rtl"
                                                lang="ar"
                                                aria-label={`الاسم العربي للعنصر ${row.id}`}
                                                className="min-w-[9rem]"
                                                value={edits[row.id]?.ar ?? row.name.ar}
                                                onChange={(event) => setEdits((current) => ({ ...current, [row.id]: { ...(current[row.id] ?? {}), ar: event.target.value } }))}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="ltr"
                                                lang="en"
                                                aria-label={`English name for item ${row.id}`}
                                                className="min-w-[9rem]"
                                                value={edits[row.id]?.en ?? row.name.en}
                                                onChange={(event) => setEdits((current) => ({ ...current, [row.id]: { ...(current[row.id] ?? {}), en: event.target.value } }))}
                                            />
                                        </TableCell>

                                        {columns.map(([column, field]) => (
                                            <TableCell key={column}>
                                                <ExtraCell
                                                    field={field}
                                                    value={rowValue(row, column)}
                                                    urlHint={typeof row.extra[`${column}_url`] === 'string' ? String(row.extra[`${column}_url`]) : null}
                                                    onChange={(value) =>
                                                        setEdits((current) => ({
                                                            ...current,
                                                            [row.id]: { ...(current[row.id] ?? {}), extra: { ...(current[row.id]?.extra ?? {}), [column]: value } },
                                                        }))
                                                    }
                                                />
                                            </TableCell>
                                        ))}

                                        <TableCell>
                                            {row.uses === 0 ? <Badge variant="neutral">غير مستخدم</Badge> : <Badge variant="outline">{row.uses}</Badge>}
                                        </TableCell>

                                        <TableCell className="text-end">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button type="button" size="sm" variant={dirty(row.id) ? 'default' : 'outline'} disabled={!dirty(row.id)} onClick={() => saveRow(row)}>
                                                    حفظ
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    disabled={row.uses > 0}
                                                    title={row.uses > 0 ? `مستخدم في ${row.uses} سجل` : 'حذف'}
                                                    aria-label={row.uses > 0 ? `لا يمكن حذف العنصر ${row.id}: مستخدم في ${row.uses} سجل` : `حذف العنصر ${row.id}`}
                                                    onClick={() => router.delete(`${base}/${row.id}`, { preserveScroll: true })}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    {pre_switch.blocked ? (
                        <Alert tone="warning" title="الإضافة موقوفة قبل ليلة التحويل">
                            {pre_switch.message}
                        </Alert>
                    ) : null}

                    {/* Add a row. Inline rather than in a dialog: the team adds a colour while
                        filling a product form in the next tab, and a modal would hide the list
                        they are checking against. */}
                    <div className="grid gap-3 rounded-lg border border-dashed p-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="new-ar" required>
                                الاسم (عربي)
                            </Label>
                            <Input id="new-ar" dir="rtl" lang="ar" value={draft.ar} onChange={(event) => setDraft({ ...draft, ar: event.target.value })} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="new-en">Name (English)</Label>
                            <Input id="new-en" dir="ltr" lang="en" value={draft.en} onChange={(event) => setDraft({ ...draft, en: event.target.value })} />
                        </div>

                        {columns.map(([column, field]) => (
                            <div key={column} className="space-y-1.5">
                                <Label htmlFor={`new-${column}`} required={field.required === true}>
                                    {field.label}
                                </Label>
                                <ExtraCell
                                    field={field}
                                    id={`new-${column}`}
                                    value={draft.extra[column] ?? (field.type === 'boolean' ? field.default === true : '')}
                                    urlHint={null}
                                    onChange={(value) => setDraft({ ...draft, extra: { ...draft.extra, [column]: value } })}
                                />
                            </div>
                        ))}

                        <div className="flex items-end">
                            <Button
                                type="button"
                                className="gap-1.5"
                                title={pre_switch.message ?? undefined}
                                disabled={draft.ar.trim() === '' || pre_switch.blocked}
                                onClick={() =>
                                    router.post(
                                        base,
                                        { name: { ar: draft.ar, en: draft.en }, extra: extraPayload(draft.extra) },
                                        { preserveScroll: true, onSuccess: () => setDraft({ ar: '', en: '', extra: {} }) },
                                    )
                                }
                            >
                                <Plus className="h-4 w-4" />
                                إضافة
                            </Button>
                        </div>
                    </div>

                    {errors['name.ar'] ? (
                        <p role="alert" className="text-xs font-medium text-destructive">
                            {errors['name.ar']}
                        </p>
                    ) : null}
                </CardContent>
            </Card>
        </ManageLayout>
    );
}

/** One extra column's control, by declared type. */
function ExtraCell({
    field,
    id,
    value,
    urlHint,
    onChange,
}: {
    field: ExtraField;
    id?: string;
    value: string | boolean;
    urlHint: string | null;
    onChange: (value: string | boolean) => void;
}) {
    if (field.type === 'boolean') {
        return <Switch id={id} aria-label={field.label} checked={value === true} onCheckedChange={onChange} />;
    }

    if (field.type === 'image') {
        return (
            <div className="min-w-[10rem] space-y-1">
                {urlHint === null ? null : <img src={urlHint} alt="" className="h-10 w-10 rounded border object-contain" />}
                <ImageField
                    label={field.label}
                    type={field.media_type ?? 'brand'}
                    value={
                        typeof value === 'string' && value !== ''
                            ? ({ file: value, folder: '', url: urlHint ?? '', width: 0, height: 0, bytes: 0, renditions: {}, skipped: [] } satisfies StoredImage)
                            : null
                    }
                    onChange={(image) => onChange(image === null ? '' : `${image.folder}/${image.file}`)}
                />
            </div>
        );
    }

    if (field.type === 'hex') {
        return (
            <div className="flex items-center gap-2">
                <span
                    aria-hidden="true"
                    className="h-6 w-6 shrink-0 rounded border"
                    style={{ background: typeof value === 'string' && /^#[0-9A-Fa-f]{6}$/.test(value) ? value : 'transparent' }}
                />
                <Input id={id} dir="ltr" className="w-28" placeholder="#RRGGBB" aria-label={field.label} value={String(value)} onChange={(event) => onChange(event.target.value)} />
            </div>
        );
    }

    return (
        <Input
            id={id}
            dir="ltr"
            className="min-w-[7rem]"
            type={field.type === 'integer' ? 'number' : 'text'}
            aria-label={field.label}
            value={String(value)}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}
