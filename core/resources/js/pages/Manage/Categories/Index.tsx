import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, CornerDownLeft, Eye, EyeOff, Info, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { ConfirmAction } from '@/components/manage/ConfirmAction';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { PreSwitchState, SharedProps } from '@/types';

interface Node {
    id: number;
    parent_id: number | null;
    depth: number;
    path: string;
    slug: string;
    icon: string;
    image_path: string;
    name: { ar: string; en: string };
    is_active: boolean;
    show_in_menu: boolean;
    sort_order: number;
    legacy_source: string | null;
    legacy_id: number | null;
    children: number;
    products: number;
    products_any: number;
    in_menu: boolean;
    in_menu_reason: string;
    family: string;
    may_delete: boolean;
}

/**
 * What the create/edit dialog posts.
 *
 * The index signature is Inertia's requirement, not decoration: `router.put` takes a
 * `Record<string, FormDataConvertible>`, so a plain interface — however correct — is refused. It
 * is written out rather than using `Record<string, unknown>` so the named fields stay checked.
 */
interface NodePayload {
    [key: string]: FormDataConvertible;
    name: { ar: string; en: string };
    slug: string | null;
    parent_id?: number | null;
    is_active: boolean;
    show_in_menu: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Array<{ value: string; label: string }>;
    nodes: Node[];
    max_depth: number;
    visibility_rule: string;
    pre_switch: PreSwitchState;
    /** Whether this storefront's tree may be edited yet (a mirrored tree may not). */
    tree_sync: PreSwitchState;
}

/**
 * /manage/storefronts/{id}/categories — the per-storefront tree (scope item 4).
 *
 * ── The screen's job is to make the RULE visible ─────────────────────────────────────────────
 *
 * Menu visibility is dynamic and permanent (study §3.3): a node shows in menus only if it is
 * active, flagged for the menu, AND it or a descendant holds a product that is visible on this
 * storefront, active and not deleted. Nothing stamps it.
 *
 * A screen showing only the two switches would let the team fight that rule — flag a node, see
 * nothing on the storefront, flag it again. So every row carries the two flags they control, the
 * live product count the rule counts, the computed answer, and the reason. "معطّل", "مستبعد من
 * القائمة يدويًا" and "لا يوجد منتج ظاهر فيه أو في فروعه" are three different problems with three
 * different fixes.
 *
 * It also shows the FAMILY a product placed in each node would get — derived by the same resolver
 * the transform uses — so the team can see that "Bags" yields `bag` before they put 200 products
 * in it.
 *
 * ── Moves are explicit, not dragged ─────────────────────────────────────────────────────────
 *
 * A parent select plus up/down buttons. Drag-and-drop across a deep RTL tree on a tablet is where
 * a wrong drop is easier than a right one, and a mis-parented category takes its whole subtree —
 * and every product URL under it — with it. The server refuses a move into a node's own subtree
 * and rewrites `path`/`depth` for the whole branch in one statement
 * (App\Domain\Catalog\CategoryTreeWriter).
 */
export default function CategoriesIndex({ storefront, storefronts, nodes, max_depth, visibility_rule, pre_switch, tree_sync }: Props) {
    /*
     * A MIRRORED tree is read-only until the write-switch: every addition, rename and re-parent is
     * made in the legacy dashboard and arrives here on the next transform run, so an edit made
     * here would be silently overwritten. The controls are disabled with the reason on them, and
     * the server refuses as well — this is presentation, that is the control (AGENTS §2.24).
     */
    const treeReadOnly = tree_sync.blocked;
    const { errors, flash } = usePage<SharedProps>().props;
    const [editing, setEditing] = useState<Node | null>(null);
    const [creatingUnder, setCreatingUnder] = useState<number | null | 'root'>(null);

    const base = `/manage/storefronts/${storefront.id}/categories`;
    /** The node whose deactivation is waiting for a confirmation, because it holds products. */
    const [deactivating, setDeactivating] = useState<Node | null>(null);

    const setActive = (node: Node, active: boolean) => {
        router.put(
            `${base}/${node.id}`,
            { name: node.name, slug: node.slug, is_active: active, show_in_menu: node.show_in_menu },
            { preserveScroll: true },
        );
    };

    const byParent = useMemo(() => {
        const map = new Map<number | null, Node[]>();
        for (const node of nodes) {
            const list = map.get(node.parent_id) ?? [];
            list.push(node);
            map.set(node.parent_id, list);
        }

        return map;
    }, [nodes]);

    const parentOptions = (exclude: Node | null) => [
        { value: '', label: '— الجذر —' },
        ...nodes
            // A node cannot become its own descendant's child: the server refuses it, and offering
            // the option would be inviting the refusal.
            .filter((node) => exclude === null || !node.path.startsWith(exclude.path))
            .filter((node) => node.depth < max_depth)
            .map((node) => ({ value: String(node.id), label: `${'— '.repeat(Math.max(0, node.depth - 1))}${node.name.ar || node.slug}` })),
    ];

    const moveSibling = (node: Node, delta: number) => {
        const siblings = byParent.get(node.parent_id) ?? [];
        const index = siblings.findIndex((item) => item.id === node.id);
        const target = index + delta;
        if (index < 0 || target < 0 || target >= siblings.length) {
            return;
        }
        const reordered = [...siblings];
        [reordered[index], reordered[target]] = [reordered[target], reordered[index]];

        router.post(
            `${base}/reorder`,
            { parent_id: node.parent_id, ids: reordered.map((item) => item.id) },
            { preserveScroll: true },
        );
    };

    return (
        <ManageLayout
            title="التصنيفات"
            crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'التصنيفات' }]}
            actions={
                <div className="flex items-center gap-2">
                    {storefronts.length > 1 ? (
                        <Select
                            aria-label="المتجر"
                            className="w-40"
                            value={String(storefront.id)}
                            onChange={(event) => router.get(`/manage/storefronts/${event.target.value}/categories`)}
                        >
                            {storefronts.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    ) : null}
                    <Button
                        type="button"
                        className="gap-1.5"
                        disabled={pre_switch.blocked}
                        title={pre_switch.message ?? undefined}
                        onClick={() => setCreatingUnder('root')}
                    >
                        <Plus className="h-4 w-4" />
                        تصنيف جذر
                    </Button>
                </div>
            }
        >
            {pre_switch.blocked ? (
                <Alert tone="warning" title="إنشاء تصنيف موقوف قبل ليلة التحويل">
                    {pre_switch.message}
                </Alert>
            ) : null}

            <Alert tone="info" title="كيف يُحسب ظهور التصنيف في القوائم">
                {visibility_rule}
            </Alert>

            {errors.tree ? (
                <Alert tone="error" title="تعذّر تنفيذ العملية">
                    {errors.tree}
                </Alert>
            ) : null}

            {treeReadOnly ? (
                <Alert tone="warning" title="شجرة هذا المتجر للقراءة فقط حتى ليلة التحويل">
                    {tree_sync.message}
                </Alert>
            ) : null}

            <Card>
                <CardHeader className="flex-row items-center justify-between gap-3">
                    <CardTitle>شجرة {storefront.name}</CardTitle>
                    <span className="text-xs text-muted-foreground">{nodes.length} تصنيفًا · أقصى عمق {max_depth}</span>
                </CardHeader>
                <CardContent className="space-y-1.5">
                    {nodes.length === 0 ? <p className="py-6 text-center text-sm text-muted-foreground">لا توجد تصنيفات بعد.</p> : null}

                    {nodes.map((node) => (
                        <div
                            key={node.id}
                            className={cn(
                                'flex flex-wrap items-center gap-2 rounded-lg border p-3',
                                !node.is_active && 'bg-muted/40 opacity-80',
                                // EMPTY is a state the team fights without understanding it: the
                                // §3.3 rule hides a node with no visible product, so the category
                                // "disappears" from the site and nothing on the screen said why.
                                // A dashed border makes it visible down the whole tree at once
                                // (task 4.3); the badge below says it in words.
                                node.products === 0 && 'border-dashed',
                            )}
                            style={{ marginInlineStart: `${(node.depth - 1) * 1.5}rem` }}
                        >
                            <div className="min-w-[12rem] flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{node.name.ar === '' ? <span className="text-destructive">— بلا اسم عربي —</span> : node.name.ar}</span>
                                    {node.name.en === '' ? null : (
                                        <span className="text-xs text-muted-foreground" dir="ltr">
                                            {node.name.en}
                                        </span>
                                    )}
                                    <span className="font-mono text-[11px] text-muted-foreground" dir="ltr">
                                        /{node.slug}
                                    </span>
                                </div>

                                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                    {/* The computed answer of the §3.3 rule, with its reason. */}
                                    {node.in_menu ? (
                                        <Badge variant="success">
                                            <Eye className="h-3 w-3" /> في القائمة
                                        </Badge>
                                    ) : (
                                        <Badge variant="neutral">
                                            <EyeOff className="h-3 w-3" /> {node.in_menu_reason}
                                        </Badge>
                                    )}
                                    {node.products === 0 ? (
                                        <Badge variant="warning" title="القاعدة تخفي أي تصنيف لا يحتوي منتجًا ظاهرًا واحدًا على الأقل">
                                            فارغ — مخفي تلقائيًا
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">{node.products} منتجًا ظاهرًا</Badge>
                                    )}
                                    {node.products_any !== node.products ? <Badge variant="neutral">{node.products_any} مرتبطًا</Badge> : null}
                                    {/* The family this node would give a product — same resolver as the transform.
                                        Empty means the node's stored `path` is malformed and no family can be
                                        derived from it; the screen says so rather than showing a blank badge. */}
                                    {node.family === '' ? (
                                        <Badge variant="warning" title="مسار هذا التصنيف غير سليم في قاعدة البيانات — لا يمكن اشتقاق العائلة منه">
                                            عائلة غير معروفة
                                        </Badge>
                                    ) : (
                                        <Badge variant="neutral" title="العائلة التي يحصل عليها المنتج الموضوع هنا — بقاعدة التحويل نفسها">
                                            {node.family}
                                        </Badge>
                                    )}
                                    {node.legacy_source === null ? (
                                        <Badge variant="outline">أُنشئ من اللوحة</Badge>
                                    ) : (
                                        <Badge variant="neutral" title="مأخوذ من النظام القديم — تعود إعادة البناء به">
                                            {node.legacy_source}#{node.legacy_id}
                                        </Badge>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <label className="flex items-center gap-1.5 text-xs">
                                    <Switch
                                        aria-label={`تفعيل ${node.name.ar || node.slug}`}
                                        disabled={treeReadOnly}
                                        checked={node.is_active}
                                        onCheckedChange={(checked) => {
                                            // Deactivating a category that HOLDS products takes
                                            // those products off the site with it, which is not
                                            // what "turn this category off" sounds like (task 4.4).
                                            if (!checked && node.products_any > 0) {
                                                setDeactivating(node);

                                                return;
                                            }
                                            setActive(node, checked);
                                        }}
                                    />
                                    مفعّل
                                </label>
                                <label className="flex items-center gap-1.5 text-xs">
                                    <Switch
                                        aria-label={`عرض ${node.name.ar || node.slug} في القائمة`}
                                        disabled={treeReadOnly}
                                        checked={node.show_in_menu}
                                        onCheckedChange={(checked) =>
                                            router.put(
                                                `${base}/${node.id}`,
                                                { name: node.name, slug: node.slug, is_active: node.is_active, show_in_menu: checked },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                    في القائمة
                                </label>
                            </div>

                            <div className="flex items-center gap-1">
                                <Button type="button" variant="ghost" size="icon" aria-label={`حرّك ${node.name.ar || node.slug} لأعلى`} onClick={() => moveSibling(node, -1)}>
                                    <ChevronUp className="h-4 w-4" />
                                </Button>
                                <Button type="button" variant="ghost" size="icon" aria-label={`حرّك ${node.name.ar || node.slug} لأسفل`} onClick={() => moveSibling(node, 1)}>
                                    <ChevronDown className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`أضف تصنيفًا تحت ${node.name.ar || node.slug}`}
                                    disabled={node.depth >= max_depth || pre_switch.blocked || treeReadOnly}
                                    title={treeReadOnly ? (tree_sync.message ?? undefined) : pre_switch.blocked ? (pre_switch.message ?? undefined) : undefined}
                                    onClick={() => setCreatingUnder(node.id)}
                                >
                                    <Plus className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`تعديل ${node.name.ar || node.slug}`}
                                    disabled={treeReadOnly}
                                    title={treeReadOnly ? (tree_sync.message ?? undefined) : undefined}
                                    onClick={() => setEditing(node)}
                                >
                                    <Pencil className="h-4 w-4" />
                                </Button>
                                <ConfirmAction
                                    title={`حذف التصنيف «${node.name.ar || node.slug}»`}
                                    consequence={
                                        <>
                                            <p>
                                                سيُحذف التصنيف من متجر <strong>{storefront.name}</strong> وحده؛ متاجر أخرى لها شجرتها المستقلة ولن
                                                يتأثر شيء فيها.
                                            </p>
                                            <p className="mt-2">
                                                التصنيف الآن بلا منتجات وبلا تصنيفات فرعية، ولذلك يُمكن حذفه. لن يفقد أي منتج
                                                بياناته، ولكن أي رابط قديم يشير إلى هذا القسم سيصبح 404.
                                            </p>
                                        </>
                                    }
                                    confirmLabel="احذف التصنيف"
                                    disabled={!node.may_delete || treeReadOnly}
                                    onConfirm={() => router.delete(`${base}/${node.id}`, { preserveScroll: true })}
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="text-destructive"
                                            disabled={!node.may_delete || treeReadOnly}
                                            title={
                                                treeReadOnly
                                                    ? (tree_sync.message ?? undefined)
                                                    : node.may_delete
                                                    ? 'حذف التصنيف'
                                                    : node.children > 0
                                                      ? `لا يمكن الحذف: يحتوي ${node.children} تصنيفًا فرعيًا. انقلها أو احذفها أولًا.`
                                                      : node.products_any > 0
                                                        ? `لا يمكن الحذف: ${node.products_any} منتجًا مرتبطًا به. انقل المنتجات إلى تصنيف آخر أولًا.`
                                                        : 'مأخوذ من النظام القديم — عطّله بدلًا من حذفه'
                                            }
                                            aria-label={`حذف ${node.name.ar || node.slug}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    }
                                />
                            </div>
                        </div>
                    ))}
                </CardContent>
            </Card>

            {/* ── edit / move ──────────────────────────────────────────────────────────── */}
            <Dialog open={editing !== null} onOpenChange={(open) => (open ? null : setEditing(null))}>
                {editing === null ? null : (
                    <DialogContent title={`تعديل: ${editing.name.ar || editing.slug}`} description="الاسم والرابط والموضع في الشجرة.">
                        <NodeForm
                            node={editing}
                            parents={parentOptions(editing)}
                            maxDepth={max_depth}
                            onSubmit={(payload) => {
                                router.put(`${base}/${editing.id}`, payload, { preserveScroll: true, onSuccess: () => setEditing(null) });
                            }}
                            onMove={(parentId) => {
                                router.put(`${base}/${editing.id}/move`, { parent_id: parentId }, { preserveScroll: true, onSuccess: () => setEditing(null) });
                            }}
                        />
                    </DialogContent>
                )}
            </Dialog>

            {/* ── create ──────────────────────────────────────────────────────────────── */}
            <Dialog open={creatingUnder !== null} onOpenChange={(open) => (open ? null : setCreatingUnder(null))}>
                {creatingUnder === null ? null : (
                    <DialogContent title="تصنيف جديد" description="الاسم العربي مطلوب. الرابط يُولَّد من الإنجليزي إن تُرك فارغًا.">
                        <NodeForm
                            node={null}
                            parentId={creatingUnder === 'root' ? null : creatingUnder}
                            parents={parentOptions(null)}
                            maxDepth={max_depth}
                            onSubmit={(payload) => {
                                router.post(base, payload, { preserveScroll: true, onSuccess: () => setCreatingUnder(null) });
                            }}
                        />
                    </DialogContent>
                )}
            </Dialog>

            {flash.status ? null : null}
            {/* ── deactivating a category that holds products (task 4.4) ─────────────── */}
            <Dialog open={deactivating !== null} onOpenChange={(open) => (open ? null : setDeactivating(null))}>
                {deactivating === null ? null : (
                    <DialogContent title={`تعطيل «${deactivating.name.ar || deactivating.slug}»`}>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                هذا التصنيف مرتبط بـ <strong>{deactivating.products_any}</strong> منتجًا على متجر {storefront.name}. تعطيله يخفي القسم
                                من القائمة ومن المسارات، ومعه تختفي منتجاته من هذا الطريق.
                            </p>
                            <p>
                                المنتجات نفسها لا تُحذف ولا تتغير حالتها: ما زال يظهر منها ما هو مرتبط بتصنيف آخر مفعّل. من كان هذا تصنيفه الوحيد لن
                                يصل إليه أحد إلا من رابطه المباشر.
                            </p>
                        </div>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setDeactivating(null)}>
                                إلغاء
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    const node = deactivating;
                                    setDeactivating(null);
                                    setActive(node, false);
                                }}
                            >
                                عطِّل التصنيف
                            </Button>
                        </div>
                    </DialogContent>
                )}
            </Dialog>

        </ManageLayout>
    );
}

/** The create/edit dialog body. Move is its own action, because it rewrites a whole subtree. */
function NodeForm({
    node,
    parentId = null,
    parents,
    maxDepth,
    onSubmit,
    onMove,
}: {
    node: Node | null;
    parentId?: number | null;
    parents: Array<{ value: string; label: string }>;
    maxDepth: number;
    onSubmit: (payload: NodePayload) => void;
    onMove?: (parentId: number | null) => void;
}) {
    const [ar, setAr] = useState(node?.name.ar ?? '');
    const [en, setEn] = useState(node?.name.en ?? '');
    const [slug, setSlug] = useState(node?.slug ?? '');
    const [parent, setParent] = useState(node === null ? (parentId === null ? '' : String(parentId)) : String(node.parent_id ?? ''));
    const [isActive, setIsActive] = useState(node?.is_active ?? true);
    const [showInMenu, setShowInMenu] = useState(node?.show_in_menu ?? true);

    const slugChanged = node !== null && slug !== node.slug;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="cat-ar" required>
                        الاسم (عربي)
                    </Label>
                    <Input id="cat-ar" dir="rtl" lang="ar" value={ar} onChange={(event) => setAr(event.target.value)} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="cat-en">Name (English)</Label>
                    <Input id="cat-en" dir="ltr" lang="en" value={en} onChange={(event) => setEn(event.target.value)} />
                </div>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="cat-slug">الرابط (slug)</Label>
                <Input id="cat-slug" dir="ltr" value={slug} onChange={(event) => setSlug(event.target.value)} />
                {slugChanged ? (
                    <p className="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        تغيير الرابط ينشئ تحويلًا 301 من الرابط القديم — الروابط المنشورة ستمر عبره.
                    </p>
                ) : null}
            </div>

            <div className="flex flex-wrap items-center gap-5">
                <label className="flex items-center gap-2 text-sm">
                    <Switch checked={isActive} onCheckedChange={setIsActive} aria-label="مفعّل" />
                    مفعّل
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <Switch checked={showInMenu} onCheckedChange={setShowInMenu} aria-label="في القائمة" />
                    في القائمة
                </label>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="cat-parent">الأب في الشجرة</Label>
                <Select id="cat-parent" value={parent} onChange={(event) => setParent(event.target.value)}>
                    {parents.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
                <p className="text-xs text-muted-foreground">
                    النقل يحرّك الفروع كلها معه ويعيد حساب المسار والعمق. أقصى عمق {maxDepth}؛ ولا يمكن النقل إلى داخل الفروع.
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    disabled={ar.trim() === ''}
                    onClick={() =>
                        onSubmit({
                            name: { ar, en },
                            slug: slug === '' ? null : slug,
                            parent_id: node === null ? (parent === '' ? null : Number(parent)) : undefined,
                            is_active: isActive,
                            show_in_menu: showInMenu,
                        })
                    }
                >
                    {node === null ? 'إنشاء' : 'حفظ'}
                </Button>

                {node !== null && onMove !== undefined && String(node.parent_id ?? '') !== parent ? (
                    <Button type="button" variant="outline" className="gap-1.5" onClick={() => onMove(parent === '' ? null : Number(parent))}>
                        <CornerDownLeft className="h-4 w-4" />
                        نقل التصنيف وفروعه
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
