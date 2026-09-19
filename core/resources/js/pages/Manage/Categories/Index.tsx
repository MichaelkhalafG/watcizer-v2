import type { FormDataConvertible } from "@inertiajs/core";
import { router, usePage } from "@inertiajs/react";
import {
    ChevronDown,
    ChevronUp,
    CornerDownLeft,
    Eye,
    EyeOff,
    Info,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import ManageLayout from "@/layouts/ManageLayout";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input, Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { useT } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import type { PreSwitchState, SharedProps } from "@/types";
import { ExportLink } from "@/components/table/ExportLink";
import { TreeRail } from "@/components/manage/TreeRail";
import { useLocale } from "@/lib/i18n";
import { ProductName } from "@/components/manage/ProductName";
import { titleOrCode } from "@/lib/title";
import { familyLabel } from "@/lib/labels";

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
    /** The node's own count PLUS every descendant's — what the branch actually holds (item 2). */
    products_subtree: number;
    products_any_subtree: number;
    in_menu: boolean;
    in_menu_reason: string;
    /** The same answer in two or three words — what the ROW shows. Empty when it is in the menu. */
    in_menu_reason_short: string;
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
    /** May this operator change the tree's SHAPE? Renaming is not covered by this (item 6). */
    tree_role: { allowed: boolean; message: string | null };
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
export default function CategoriesIndex({
    storefront,
    storefronts,
    nodes,
    max_depth,
    visibility_rule,
    pre_switch,
    tree_role,
    tree_sync,
}: Props) {
    /*
     * A MIRRORED tree is read-only until the write-switch: every addition, rename and re-parent is
     * made in the legacy dashboard and arrives here on the next transform run, so an edit made
     * here would be silently overwritten. The controls are disabled with the reason on them, and
     * the server refuses as well — this is presentation, that is the control (AGENTS §2.24).
     */
    const treeReadOnly = tree_sync.blocked;
    /*
     * The tree's SHAPE is locked by either rule; its CONTENT only by the calendar (item 6).
     *
     *   • `treeReadOnly` — the CALENDAR. This storefront's tree is mirrored from legacy until
     *     switch night, so any edit here would be undone. True for everybody.
     *   • `shapeLocked` — the calendar OR the PERSON. Adding, moving, reordering, deleting and
     *     switching a category OFF move products, breadcrumbs and menus underneath themselves, so
     *     they are an administrator's job on any day.
     *
     * Renaming and "show in the menu" stay on the first rule alone, because they are data-entry's
     * daily work and change a word on a page rather than where 7,713 products live.
     *
     * `shapeReason` names the ROLE first: telling a data-entry operator to wait for switch night
     * when the real answer is "ask an administrator" sends them to wait for the wrong thing.
     */
    const shapeLocked = treeReadOnly || !tree_role.allowed;
    const shapeReason = tree_role.message ?? tree_sync.message;
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    /*
     * ── One name, in the reader's language (2026-10-05) ────────────────────────
     *
     * This screen printed BOTH names on every node — «ساعات Watches», «جي إم تي GMT» — so an
     * English operator read a bilingual string instead of a name, and every dialog, tooltip and
     * `aria-label` on the screen said that field outright. // name-seam-exempt: prose, not a read
     *
     * It is the same defect item 1b fixed for the products list on 2026-09-17, in a screen that
     * never adopted the fix. `localisedTitle` is that rule and `ProductName` draws it; both are
     * used here now, and `ProductNameSeamTest` has been widened so a category name cannot go back
     * to picking a language for the reader.
     *
     * `nodeName` is for the places that need a bare string — an `aria-label`, a confirmation
     * sentence — and falls back to the slug, which is what somebody would search for anyway.
     */
    const nodeName = (node: Node): string =>
        titleOrCode(node.name, locale, node.slug);

    const [editing, setEditing] = useState<Node | null>(null);
    const [creatingUnder, setCreatingUnder] = useState<number | null | "root">(
        null,
    );

    const base = `/manage/storefronts/${storefront.id}/categories`;
    /** The node whose deactivation is waiting for a confirmation, because it holds products. */
    const [deactivating, setDeactivating] = useState<Node | null>(null);
    /*
     * Delete moved behind the row's overflow menu (§2.9), so its dialog moved out of the row with
     * it: Radix unmounts a menu's contents when it closes, and a dialog rendered inside would
     * vanish the instant the item that opened it was chosen.
     */
    const [deleting, setDeleting] = useState<Node | null>(null);

    const setActive = (node: Node, active: boolean) => {
        router.put(
            `${base}/${node.id}`,
            {
                name: node.name,
                slug: node.slug,
                is_active: active,
                show_in_menu: node.show_in_menu,
            },
            { preserveScroll: true },
        );
    };

    /*
     * ── Collapse state (item 2, 2026-09-18) ──────────────────────────────────────
     *
     * In `sessionStorage`, not in component state, and the reason is Inertia. Every mutation on
     * this screen — a rename, a reorder, a toggle — is a full page visit, so component state is
     * rebuilt from nothing each time. A tree that re-opens all sixty-one nodes every time somebody
     * nudges one row is worse than a tree that never collapsed.
     *
     * Per STOREFRONT, because the two trees are different shapes and a node id means nothing across
     * them. Session rather than local: an operator coming back tomorrow should see the whole tree,
     * not yesterday's half-folded view of it.
     *
     * Every read and write is wrapped: storage throws in a private window and in a browser with
     * site data blocked, and a category screen must not go blank because of it.
     */
    const collapseKey = `manage.categories.collapsed.${storefront.id}`;

    const [collapsed, setCollapsed] = useState<Set<number>>(() => {
        try {
            const raw = sessionStorage.getItem(collapseKey);
            const parsed: unknown = raw === null ? [] : JSON.parse(raw);

            return new Set(Array.isArray(parsed) ? parsed.filter((id): id is number => typeof id === "number") : []);
        } catch {
            return new Set();
        }
    });

    useEffect(() => {
        try {
            sessionStorage.setItem(collapseKey, JSON.stringify([...collapsed]));
        } catch {
            // Nothing to do and nothing to say: the tree works, it just will not be remembered.
        }
    }, [collapseKey, collapsed]);

    const toggle = (id: number) =>
        setCollapsed((current) => {
            const next = new Set(current);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    /*
     * The rows actually drawn, and whether each is the LAST of its level — which is what decides
     * where the rail's line stops.
     *
     * `nodes` arrives depth-first with each level in the team's order (item 3 fixed that, and
     * `CategoryOrderTest` holds it), so a node's descendants are exactly the rows that follow it
     * with a greater depth, up to the next row at its own depth or shallower. That makes hiding a
     * subtree a single scan with no tree to rebuild.
     */
    const visible = useMemo(() => {
        const out: Array<{ node: Node; isLast: boolean; hasChildren: boolean }> = [];
        let hiddenBelow: number | null = null;

        for (let i = 0; i < nodes.length; i++) {
            const node = nodes[i];

            if (hiddenBelow !== null) {
                if (node.depth > hiddenBelow) {
                    continue;
                }
                hiddenBelow = null;
            }

            const next = nodes[i + 1];
            const hasChildren = next !== undefined && next.depth > node.depth;
            // Last of its level: nothing after it, or the next row is shallower. A sibling at the
            // same depth means the ancestor line has to keep running past this row.
            const isLast =
                nodes.slice(i + 1).find((other) => other.depth <= node.depth)?.depth !== node.depth;

            out.push({ node, isLast, hasChildren });

            if (hasChildren && collapsed.has(node.id)) {
                hiddenBelow = node.depth;
            }
        }

        return out;
    }, [nodes, collapsed]);

    /** Every node that HAS children — what "collapse all" needs and what "expand all" clears. */
    const branches = useMemo(
        () => nodes.filter((node, index) => nodes[index + 1] !== undefined && nodes[index + 1].depth > node.depth).map((node) => node.id),
        [nodes],
    );

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
        { value: "", label: t("categories.root_option", "— الجذر —") },
        ...nodes
            // A node cannot become its own descendant's child: the server refuses it, and offering
            // the option would be inviting the refusal.
            .filter(
                (node) =>
                    exclude === null || !node.path.startsWith(exclude.path),
            )
            .filter((node) => node.depth < max_depth)
            .map((node) => ({
                value: String(node.id),
                label: `${"— ".repeat(Math.max(0, node.depth - 1))}${nodeName(node)}`,
            })),
    ];

    const moveSibling = (node: Node, delta: number) => {
        const siblings = byParent.get(node.parent_id) ?? [];
        const index = siblings.findIndex((item) => item.id === node.id);
        const target = index + delta;
        if (index < 0 || target < 0 || target >= siblings.length) {
            return;
        }
        const reordered = [...siblings];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        router.post(
            `${base}/reorder`,
            {
                parent_id: node.parent_id,
                ids: reordered.map((item) => item.id),
            },
            { preserveScroll: true },
        );
    };

    return (
        <ManageLayout
            title={t("categories.title", "التصنيفات")}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("categories.title", "التصنيفات") },
            ]}
            actions={
                <div className="flex items-center gap-2">
                    <ExportLink count={nodes.length} />
                    {storefronts.length > 1 ? (
                        <Select
                            aria-label={t("common.storefront", "المتجر")}
                            className="w-40"
                            value={String(storefront.id)}
                            onChange={(event) =>
                                router.get(
                                    `/manage/storefronts/${event.target.value}/categories`,
                                )
                            }
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
                        disabled={pre_switch.blocked || !tree_role.allowed}
                        title={
                            (tree_role.message ??
                                pre_switch.message ??
                                pre_switch.caveat) ??
                            undefined
                        }
                        onClick={() => setCreatingUnder("root")}
                    >
                        <Plus className="h-4 w-4" />
                        {t("categories.root_category", "تصنيف جذر")}
                    </Button>
                </div>
            }
        >
            {/* Only the refusal is left. The caveat beside it — "creating categories is open
                before switch night" — went with the rest of the pre-switch notices on 2026-09-18. */}
            {pre_switch.blocked ? (
                <Alert
                    tone="warning"
                    title={t("categories.create_blocked_title", "إضافة التصنيفات موقوفة حاليًا")}
                >
                    {pre_switch.message}
                </Alert>
            ) : null}

            <Alert
                tone="info"
                title={t(
                    "categories.visibility_rule_title",
                    "كيف يُحسب ظهور التصنيف في القوائم",
                )}
            >
                {visibility_rule}
            </Alert>

            {errors.tree ? (
                <Alert
                    tone="error"
                    title={t("common.action_failed", "تعذّر تنفيذ العملية")}
                >
                    {errors.tree}
                </Alert>
            ) : null}

            {shapeLocked || tree_sync.caveat !== null ? (
                <Alert
                    tone="warning"
                    title={
                        shapeLocked
                            ? tree_role.allowed
                                ? t(
                                      "categories.read_only_title",
                                      "تصنيفات هذا المتجر للقراءة فقط",
                                  )
                                : t(
                                      "categories.tree_admin_only_title",
                                      "تعديل شكل الشجرة للمدير فقط",
                                  )
                            : t(
                                  "categories.mirror_caveat_title",
                                  "تصنيفات هذا المتجر تتبع متجر واتشيزر",
                              )
                    }
                >
                    {shapeLocked ? shapeReason : tree_sync.caveat}
                </Alert>
            ) : null}

            <Card>
                <CardHeader className="flex-row items-center justify-between gap-3">
                    <CardTitle>
                        {t("categories.tree_of", "شجرة :name", {
                            name: storefront.name,
                        })}
                    </CardTitle>
                    {/* Two different things, and they used to be one. `أقصى عمق 10` read as a fact
                        about THIS tree, which is 3 deep — the 10 is the system's ceiling. The
                        depth the tree actually has is the useful number, so it is stated; the
                        ceiling is named as a ceiling and moved into the tooltip, where it answers
                        the only question it is ever asked ("can I nest one more?"). */}
                    <span
                        className="text-xs text-muted-foreground"
                        title={t(
                            "categories.depth_limit",
                            "أقصى عمق مسموح به في النظام: :depth مستويات",
                            { depth: max_depth },
                        )}
                    >
                        {t(
                            "categories.count_and_depth",
                            ":count تصنيفًا · :depth مستويات",
                            {
                                count: nodes.length,
                                depth: nodes.reduce(
                                    (deepest, node) =>
                                        node.depth > deepest ? node.depth : deepest,
                                    0,
                                ),
                            },
                        )}
                    </span>
                    {/* Fold the whole tree, or open it (item 2). At 61 nodes an operator looking
                        for one branch should not have to scroll past the other five. Offered only
                        when there is something to fold. */}
                    {branches.length === 0 ? null : (
                        <div className="flex items-center gap-1.5">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setCollapsed(new Set(branches))}
                                disabled={collapsed.size >= branches.length}
                            >
                                {t("categories.collapse_all", "اطوِ الكل")}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setCollapsed(new Set())}
                                disabled={collapsed.size === 0}
                            >
                                {t("categories.expand_all", "افتح الكل")}
                            </Button>
                        </div>
                    )}
                </CardHeader>
                <CardContent className="space-y-1.5">
                    {nodes.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {t("categories.empty", "لا توجد تصنيفات بعد.")}
                        </p>
                    ) : null}

                    {/* One row per VISIBLE node. The rail on the left draws the parentage (item 2);
                        a collapsed branch simply is not in this list. */}
                    {visible.map(({ node, isLast, hasChildren }) => (
                        <div key={node.id} className="flex items-stretch">
                            <TreeRail
                                depth={node.depth}
                                isLast={isLast}
                                hasChildren={hasChildren}
                                collapsed={collapsed.has(node.id)}
                                onToggle={() => toggle(node.id)}
                                rtl={locale === "ar"}
                                label={nodeName(node)}
                            />
                        <div
                            className={cn(
                                "flex min-h-9 flex-1 items-center gap-2 overflow-hidden rounded-md border px-2.5 py-1.5",
                                !node.is_active && "bg-muted/40 opacity-80",
                                // EMPTY is a state the team fights without understanding it: the
                                // §3.3 rule hides a node with no visible product, so the category
                                // "disappears" from the site and nothing on the screen said why.
                                // A dashed border makes it visible down the whole tree at once
                                // (task 4.3); the badge below says it in words.
                                //
                                // The BRANCH total, not the node's own (item 2): the §3.3 rule
                                // that hides a category looks at the whole branch, so marking a
                                // parent "empty" because nothing is pinned directly to it
                                // contradicted the "in the menu" badge sitting next to it.
                                node.products_subtree === 0 && "border-dashed",
                                // A branch that is folded says so on the row itself, so a count
                                // that looks wrong ("3 products" on a node showing none) has its
                                // explanation in the same glance.
                                hasChildren && collapsed.has(node.id) && "border-dashed bg-muted/30",
                            )}
                        >
                            {/* ── ONE LINE per node (§2.9) ──────────────────────────────────

                                Each node used to be a 73 px full-width bordered card: the name on
                                one line, then a wrapping row of up to five badges under it. At 61
                                nodes the screen read as a stack of slightly ragged rows, and the
                                indentation — real, and drawn by `TreeRail` — was imperceptible
                                against that much card.

                                What stays on the line is what the operator acts on: the name, the
                                slug, whether the §3.3 rule shows it, and ONE number. What moved
                                into the row's tooltip is everything that answers "why" rather than
                                "what" — the family this node would give a product, where it came
                                from, and how many placements it holds including hidden ones.

                                The number is the BRANCH total when it differs from the node's own,
                                because that is the one the visibility rule uses and the only one
                                still true when the branch is folded. Three numbers side by side —
                                `4683 منتجًا ظاهرًا`, `7823 في الفرع`, `4688 مرتبطًا` — asked the
                                reader to work out the relationship between them on every row. */}
                            <div
                                className="flex min-w-0 flex-1 items-center gap-2"
                                title={[
                                    node.family === ""
                                        ? t(
                                              "categories.unknown_family_hint",
                                              "مسار هذا التصنيف غير سليم في قاعدة البيانات — لا يمكن اشتقاق العائلة منه",
                                          )
                                        : t(
                                              "categories.family_of",
                                              "العائلة: :family",
                                              { family: familyLabel(t, node.family) },
                                          ),
                                    t("categories.linked_products", ":count مرتبطًا", {
                                        count: node.products_any,
                                    }),
                                    node.legacy_source === null
                                        ? t("categories.created_here", "أُنشئ من اللوحة")
                                        : t("categories.legacy_hint", "مأخوذ من متجر واتشيزر"),
                                ].join(t("common.list_separator", "، "))}
                            >
                                {/* ── The name is never the thing that loses width (2026-10-05)

                                    `shrink-0` on the name, `min-w-0 truncate` on the two beside
                                    it: flexbox takes the space back from the items that ALLOW it,
                                    so the English name and the slug shorten first and the Arabic
                                    name — the only thing on the row an operator navigates by —
                                    keeps its full text. The `truncate` here is a last resort for a
                                    pathological name; measured at 1366px on this tree, no node
                                    reaches it. */}
                                <span className="shrink-0 truncate font-medium">
                                    <ProductName
                                        title={node.name}
                                        secondary={false}
                                    />
                                </span>

                                {/* Below 2xl the slug goes entirely: it is a URL fragment, it is
                                    in the edit dialog and in the export, and nobody scans a tree
                                    by it. It was the second widest thing on the row. */}
                                <span
                                    className="hidden min-w-0 truncate font-mono text-[11px] text-muted-foreground 2xl:inline"
                                    dir="ltr"
                                >
                                    /{node.slug}
                                </span>

                                {/* The computed answer of the §3.3 rule. Kept inline because it is
                                    the one thing on the row that says whether customers can reach
                                    this section at all. */}
                                {/* ── ONE statement about whether customers can reach this
                                       section — and it is a CHIP, not a paragraph ─────────

                                    2026-09-19: the row used to carry a `فارغ — مخفي
                                    تلقائيًا` badge next to a toggle reading `مفعّل`, with nothing
                                    saying which caused which. That was replaced with the server's
                                    whole explanatory sentence, ending in the remedy.

                                    2026-10-05: printing that sentence on EVERY row is what broke
                                    this screen. Sixty nodes carried the same paragraph, it took
                                    half the row, and the category names truncated to `سـ…`. The
                                    developer found it by looking at the tree.

                                    So the rule, applied here and on the lookups screen: **the row
                                    carries a short state chip and nothing more; the sentence
                                    appears once, where the operator is dealing with that row.**
                                    Here that is the chip's own tooltip and the edit dialog. */}
                                {node.in_menu ? (
                                    <Badge variant="success" className="shrink-0">
                                        <Eye className="h-3 w-3" />{" "}
                                        {t("categories.in_menu", "في القائمة")}
                                    </Badge>
                                ) : (
                                    // The full sentence lives on the hover and in the edit dialog
                                    // — see the note above. Here: three words and the cause.
                                    <Badge
                                        variant="neutral"
                                        className="shrink-0"
                                        title={node.in_menu_reason}
                                    >
                                        <EyeOff className="h-3 w-3" />{" "}
                                        {t("categories.hidden_short", "مخفي")}
                                        {node.in_menu_reason_short === ""
                                            ? null
                                            : ` — ${node.in_menu_reason_short}`}
                                    </Badge>
                                )}

                                {node.products_subtree === 0 ? null : (
                                    <Badge
                                        variant="neutral"
                                        className="shrink-0"
                                        title={
                                            node.products_subtree > node.products
                                                ? t(
                                                      "categories.branch_products_hint",
                                                      "إجمالي المنتجات الظاهرة في هذا التصنيف وكل التصنيفات التي تحته",
                                                  )
                                                : t(
                                                      "categories.visible_products_hint",
                                                      "المنتجات الظاهرة الموضوعة في هذا التصنيف",
                                                  )
                                        }
                                    >
                                        {node.products_subtree > node.products
                                            ? t(
                                                  "categories.branch_products",
                                                  ":count في الفرع",
                                                  { count: node.products_subtree },
                                              )
                                            : t(
                                                  "categories.visible_products",
                                                  ":count منتجًا ظاهرًا",
                                                  { count: node.products },
                                              )}
                                    </Badge>
                                )}
                            </div>

                            {/* ── ONE inline toggle, not two (2026-10-05) ───────────────

                                §2.9 made the two toggles stop looking like one another. The
                                developer's next look said there are still too many controls on a
                                category row, and they are right — at sixty nodes, two switches,
                                two chevrons and a menu is five controls per row.

                                So the one that stays inline is the one this SCREEN is for:
                                «في القائمة», which is reversible, harmless and used repeatedly while
                                arranging a menu. «مفعّل» moved into the overflow menu: it takes
                                the category AND its products off the site, it is used rarely, and
                                a destructive control does not belong under the cursor on every
                                row. Its confirmation dialog is unchanged — only the trigger
                                moved. */}
                            <div className="flex shrink-0 items-center gap-3">
                                <label
                                    className="flex items-center gap-1.5 text-xs"
                                    title={t(
                                        "categories.toggle_in_menu_hint",
                                        "إخفاؤه من القائمة لا يوقف التصنيف: صفحته تبقى تعمل ومنتجاته تبقى معروضة.",
                                    )}
                                >
                                    <Switch
                                        aria-label={t(
                                            "categories.toggle_in_menu",
                                            "عرض :name في القائمة",
                                            {
                                                name: nodeName(node),
                                            },
                                        )}
                                        disabled={treeReadOnly}
                                        checked={node.show_in_menu}
                                        onCheckedChange={(checked) =>
                                            router.put(
                                                `${base}/${node.id}`,
                                                {
                                                    name: node.name,
                                                    slug: node.slug,
                                                    is_active: node.is_active,
                                                    show_in_menu: checked,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                    <span className="hidden lg:inline">
                                        {t("categories.in_menu", "في القائمة")}
                                    </span>
                                </label>
                            </div>

                            {/* ── Eight controls became one (§2.9) ──────────────────────────

                                Every row carried delete, edit, add-child, move-down, move-up, two
                                toggles and a chevron: at 61 nodes, roughly **490 controls on one
                                screen**. The red trash was the leftmost and most prominent of
                                them, so the most destructive thing on the row was the first thing
                                under the cursor on every single row.

                                Reorder stays outside the menu because it is used repeatedly and in
                                pairs — putting it two clicks away would make the one job this
                                screen exists for slower. Everything else is behind the menu, and
                                delete is last, separated, and marked. */}
                            <div className="flex shrink-0 items-center gap-0.5">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7"
                                    aria-label={t(
                                        "categories.move_up",
                                        "حرّك :name لأعلى",
                                        { name: nodeName(node) },
                                    )}
                                    // These two carried NO disabled state at all (item 6): on a
                                    // mirrored tree, or for an operator without the grant, they
                                    // looked live and the save was refused after the click.
                                    disabled={shapeLocked}
                                    title={
                                        shapeLocked
                                            ? (shapeReason ?? undefined)
                                            : undefined
                                    }
                                    onClick={() => moveSibling(node, -1)}
                                >
                                    <ChevronUp className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7"
                                    aria-label={t(
                                        "categories.move_down",
                                        "حرّك :name لأسفل",
                                        { name: nodeName(node) },
                                    )}
                                    disabled={shapeLocked}
                                    title={
                                        shapeLocked
                                            ? (shapeReason ?? undefined)
                                            : undefined
                                    }
                                    onClick={() => moveSibling(node, 1)}
                                >
                                    <ChevronDown className="h-4 w-4" />
                                </Button>

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7"
                                            aria-label={t(
                                                "categories.row_menu",
                                                "إجراءات :name",
                                                {
                                                    name:
                                                        nodeName(node),
                                                },
                                            )}
                                        >
                                            <MoreHorizontal className="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            disabled={treeReadOnly}
                                            onSelect={() => setEditing(node)}
                                        >
                                            <Pencil className="h-4 w-4" />
                                            {t("common.edit", "تعديل")}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            disabled={
                                                node.depth >= max_depth ||
                                                pre_switch.blocked ||
                                                shapeLocked
                                            }
                                            onSelect={() =>
                                                setCreatingUnder(node.id)
                                            }
                                        >
                                            <Plus className="h-4 w-4" />
                                            {t(
                                                "categories.add_child_label",
                                                "أضف تصنيفًا فرعيًا",
                                            )}
                                        </DropdownMenuItem>

                                        {/* The reason a disabled item is disabled, as TEXT inside
                                            the menu (J-8). A greyed row that will not say why
                                            reads as a broken dashboard rather than as a rule, and
                                            a `title` never appears on touch at all. */}
                                        {treeReadOnly && tree_sync.message ? (
                                            <p className="px-2 py-1.5 text-[11px] leading-snug text-muted-foreground">
                                                {tree_sync.message}
                                            </p>
                                        ) : null}
                                        {shapeLocked && shapeReason ? (
                                            <p className="px-2 py-1.5 text-[11px] leading-snug text-muted-foreground">
                                                {shapeReason}
                                            </p>
                                        ) : null}

                                        {/* Moved off the row (2026-10-05). Same behaviour, same
                                            confirmation — a different place to press it. */}
                                        <DropdownMenuItem
                                            disabled={shapeLocked}
                                            onSelect={(event) => {
                                                // Deactivating a category that HOLDS products
                                                // takes those products off the site with it,
                                                // which is not what "turn this category off"
                                                // sounds like (task 4.4). The dialog opens from
                                                // state for the same reason delete's does.
                                                if (
                                                    node.is_active &&
                                                    node.products_any > 0
                                                ) {
                                                    event.preventDefault();
                                                    setDeactivating(node);

                                                    return;
                                                }
                                                setActive(node, !node.is_active);
                                            }}
                                        >
                                            {node.is_active ? (
                                                <EyeOff className="h-4 w-4" />
                                            ) : (
                                                <Eye className="h-4 w-4" />
                                            )}
                                            {node.is_active
                                                ? t(
                                                      "categories.deactivate_action",
                                                      "أوقف التصنيف ومنتجاته",
                                                  )
                                                : t(
                                                      "categories.activate_action",
                                                      "أعد تفعيل التصنيف",
                                                  )}
                                        </DropdownMenuItem>

                                        <DropdownMenuSeparator />

                                        {node.may_delete && !shapeLocked ? (
                                            <DropdownMenuItem
                                                className="text-destructive"
                                                onSelect={(event) => {
                                                    // The dialog opens from state, not from the
                                                    // menu item — Radix closes the menu on select
                                                    // and would unmount a dialog rendered inside.
                                                    event.preventDefault();
                                                    setDeleting(node);
                                                }}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                                {t(
                                                    "categories.delete_action",
                                                    "حذف التصنيف",
                                                )}
                                            </DropdownMenuItem>
                                        ) : (
                                            <p className="px-2 py-1.5 text-[11px] leading-snug text-muted-foreground">
                                                {shapeLocked
                                                    ? (shapeReason ?? "")
                                                    : node.children > 0
                                                      ? t(
                                                            "categories.delete_blocked_children",
                                                            "لا يمكن الحذف: يحتوي :count تصنيفًا فرعيًا. انقلها أو احذفها أولًا.",
                                                            {
                                                                count: node.children,
                                                            },
                                                        )
                                                      : node.products_any > 0
                                                        ? t(
                                                              "categories.delete_blocked_products",
                                                              "لا يمكن الحذف: :count منتجًا مرتبطًا به. انقل المنتجات إلى تصنيف آخر أولًا.",
                                                              {
                                                                  count: node.products_any,
                                                              },
                                                          )
                                                        : t(
                                                              "categories.delete_blocked_legacy",
                                                              "مأخوذ من متجر واتشيزر — عطّله بدلًا من حذفه",
                                                          )}
                                            </p>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                        </div>
                    ))}
                </CardContent>
            </Card>

            {/* ── edit / move ──────────────────────────────────────────────────────────── */}
            <Dialog
                open={editing !== null}
                onOpenChange={(open) => (open ? null : setEditing(null))}
            >
                {editing === null ? null : (
                    <DialogContent
                        title={t(
                            "categories.edit_dialog_title",
                            "تعديل: :name",
                            { name: nodeName(editing) },
                        )}
                        description={t(
                            "categories.edit_dialog_description",
                            "الاسم والرابط والموضع في الشجرة.",
                        )}
                    >
                        {/* ── The explanation, ONCE, here (2026-10-05) ─────────────────

                            The row shows a two-word chip; this is where the whole sentence
                            belongs, because this is the node the operator has actually opened and
                            there is room for a sentence without anything else losing width.

                            Only when the node is NOT in the menu: a node that is shown needs no
                            explanation, and a dialog that always carries a paragraph is the same
                            mistake one level down. */}
                        {editing.in_menu ? null : (
                            <Alert
                                tone="warning"
                                title={t(
                                    "categories.hidden_dialog_title",
                                    "لا يظهر في قائمة المتجر",
                                )}
                            >
                                <p>{editing.in_menu_reason}</p>
                            </Alert>
                        )}

                        <NodeForm
                            node={editing}
                            parents={parentOptions(editing)}
                            maxDepth={max_depth}
                            onSubmit={(payload) => {
                                router.put(`${base}/${editing.id}`, payload, {
                                    preserveScroll: true,
                                    onSuccess: () => setEditing(null),
                                });
                            }}
                            onMove={(parentId) => {
                                router.put(
                                    `${base}/${editing.id}/move`,
                                    { parent_id: parentId },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setEditing(null),
                                    },
                                );
                            }}
                        />
                    </DialogContent>
                )}
            </Dialog>

            {/* ── create ──────────────────────────────────────────────────────────────── */}
            <Dialog
                open={creatingUnder !== null}
                onOpenChange={(open) => (open ? null : setCreatingUnder(null))}
            >
                {creatingUnder === null ? null : (
                    <DialogContent
                        title={t("categories.new_title", "تصنيف جديد")}
                        description={t(
                            "categories.new_description",
                            "الاسم العربي مطلوب. الرابط يُولَّد من الإنجليزي إن تُرك فارغًا.",
                        )}
                    >
                        <NodeForm
                            node={null}
                            parentId={
                                creatingUnder === "root" ? null : creatingUnder
                            }
                            parents={parentOptions(null)}
                            maxDepth={max_depth}
                            onSubmit={(payload) => {
                                router.post(base, payload, {
                                    preserveScroll: true,
                                    onSuccess: () => setCreatingUnder(null),
                                });
                            }}
                        />
                    </DialogContent>
                )}
            </Dialog>

            {/* ── deleting a category, from the row's overflow menu (§2.9) ────────────── */}
            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => (open ? null : setDeleting(null))}
            >
                {deleting === null ? null : (
                    <DialogContent
                        title={t(
                            "categories.delete_title",
                            "حذف التصنيف «:name»",
                            { name: nodeName(deleting) },
                        )}
                    >
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                {t(
                                    "categories.delete_consequence_before",
                                    "سيُحذف التصنيف من متجر",
                                )}{" "}
                                <strong>{storefront.name}</strong>{" "}
                                {t(
                                    "categories.delete_consequence_after",
                                    "وحده؛ متاجر أخرى لها شجرتها المستقلة ولن يتأثر شيء فيها.",
                                )}
                            </p>
                            <p>
                                {t(
                                    "categories.delete_consequence_note",
                                    "لن يفقد أي منتج بياناته، لكن أي رابط قديم يشير إلى هذا القسم لن يعمل بعد الحذف.",
                                )}
                            </p>
                        </div>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDeleting(null)}
                            >
                                {t("common.cancel", "إلغاء")}
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    const node = deleting;
                                    setDeleting(null);
                                    router.delete(`${base}/${node.id}`, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                {t(
                                    "categories.delete_confirm",
                                    "احذف التصنيف",
                                )}
                            </Button>
                        </div>
                    </DialogContent>
                )}
            </Dialog>

            {/* ── deactivating a category that holds products (task 4.4) ─────────────── */}
            <Dialog
                open={deactivating !== null}
                onOpenChange={(open) => (open ? null : setDeactivating(null))}
            >
                {deactivating === null ? null : (
                    <DialogContent
                        title={t(
                            "categories.deactivate_title",
                            "تعطيل «:name»",
                            {
                                name: nodeName(deactivating),
                            },
                        )}
                    >
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                {t(
                                    "categories.deactivate_body_before",
                                    "هذا التصنيف مرتبط بـ",
                                )}{" "}
                                <strong>{deactivating.products_any}</strong>{" "}
                                {t(
                                    "categories.deactivate_body_after",
                                    "منتجًا على متجر :storefront. تعطيله يخفي القسم من القائمة ومن المسارات، ومعه تختفي منتجاته من هذا الطريق.",
                                    { storefront: storefront.name },
                                )}
                            </p>
                            <p>
                                {t(
                                    "categories.deactivate_body_note",
                                    "المنتجات نفسها لا تُحذف ولا تتغير حالتها: ما زال يظهر منها ما هو مرتبط بتصنيف آخر مفعّل. من كان هذا تصنيفه الوحيد لن يصل إليه أحد إلا من رابطه المباشر.",
                                )}
                            </p>
                        </div>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDeactivating(null)}
                            >
                                {t("common.cancel", "إلغاء")}
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
                                {t(
                                    "categories.deactivate_confirm",
                                    "عطِّل التصنيف",
                                )}
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
    const t = useT();
    // An EDITOR: this form offers an Arabic box and an English box, so it has to read each
    // language on its own. Choosing one for a READER is what the guard forbids.
    // name-seam-exempt: the Arabic name's own box
    const [ar, setAr] = useState(node?.name.ar ?? "");
    const [en, setEn] = useState(node?.name.en ?? ""); // name-seam-exempt: the editor's other box
    const [slug, setSlug] = useState(node?.slug ?? "");
    const [parent, setParent] = useState(
        node === null
            ? parentId === null
                ? ""
                : String(parentId)
            : String(node.parent_id ?? ""),
    );
    const [isActive, setIsActive] = useState(node?.is_active ?? true);
    const [showInMenu, setShowInMenu] = useState(node?.show_in_menu ?? true);

    const slugChanged = node !== null && slug !== node.slug;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="cat-ar" required>
                        {t("common.name_ar", "الاسم (عربي)")}
                    </Label>
                    <Input
                        id="cat-ar"
                        dir="rtl"
                        lang="ar"
                        value={ar}
                        onChange={(event) => setAr(event.target.value)}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="cat-en">
                        {t("common.name_en", "الاسم (إنجليزي)")}
                    </Label>
                    <Input
                        id="cat-en"
                        dir="ltr"
                        lang="en"
                        value={en}
                        onChange={(event) => setEn(event.target.value)}
                    />
                </div>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="cat-slug">
                    {t("common.slug", "الرابط (slug)")}
                </Label>
                <Input
                    id="cat-slug"
                    dir="ltr"
                    value={slug}
                    onChange={(event) => setSlug(event.target.value)}
                />
                {slugChanged ? (
                    <p className="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                        <Info
                            className="mt-0.5 h-3.5 w-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {t(
                            "categories.slug_change_note",
                            "تغيير الرابط ينشئ تحويلًا 301 من الرابط القديم — الروابط المنشورة ستمر عبره.",
                        )}
                    </p>
                ) : null}
            </div>

            <div className="flex flex-wrap items-center gap-5">
                <label className="flex items-center gap-2 text-sm">
                    <Switch
                        checked={isActive}
                        onCheckedChange={setIsActive}
                        aria-label={t("common.active", "مفعّل")}
                    />
                    {t("common.active", "مفعّل")}
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <Switch
                        checked={showInMenu}
                        onCheckedChange={setShowInMenu}
                        aria-label={t("categories.in_menu", "في القائمة")}
                    />
                    {t("categories.in_menu", "في القائمة")}
                </label>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="cat-parent">
                    {t("categories.parent", "الأب في الشجرة")}
                </Label>
                <Select
                    id="cat-parent"
                    value={parent}
                    onChange={(event) => setParent(event.target.value)}
                >
                    {parents.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
                <p className="text-xs text-muted-foreground">
                    {t(
                        "categories.move_help",
                        "النقل يحرّك الفروع كلها معه ويعيد حساب المسار والعمق. أقصى عمق :depth؛ ولا يمكن النقل إلى داخل الفروع.",
                        { depth: maxDepth },
                    )}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    disabled={ar.trim() === ""}
                    onClick={() =>
                        onSubmit({
                            name: { ar, en },
                            slug: slug === "" ? null : slug,
                            parent_id:
                                node === null
                                    ? parent === ""
                                        ? null
                                        : Number(parent)
                                    : undefined,
                            is_active: isActive,
                            show_in_menu: showInMenu,
                        })
                    }
                >
                    {node === null
                        ? t("categories.create", "إنشاء")
                        : t("common.save", "حفظ")}
                </Button>

                {node !== null &&
                onMove !== undefined &&
                String(node.parent_id ?? "") !== parent ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="gap-1.5"
                        onClick={() =>
                            onMove(parent === "" ? null : Number(parent))
                        }
                    >
                        <CornerDownLeft className="h-4 w-4" />
                        {t("categories.move_button", "نقل التصنيف وفروعه")}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
