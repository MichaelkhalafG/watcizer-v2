import { ChevronLeft, ChevronRight } from "lucide-react";

import { useT } from "@/lib/i18n";
import { cn } from "@/lib/utils";

/**
 * The left-hand rail that makes a flat list read as a tree (item 2, 2026-09-18).
 *
 * ── The problem it solves ────────────────────────────────────────────────────────────────────
 *
 * The category screen already indented each row by `depth`. The developer's verdict was still
 * *"it reads as a flat list and it's confusing"*, and they were right: 1.5rem of margin is nothing
 * beside a full-width bordered card, so sixty-one rows looked like sixty-one rows. Depth you have
 * to measure is depth nobody reads.
 *
 * So parentage is DRAWN. One vertical line per ancestor level, an elbow into the row itself, and a
 * chevron where a node has children. The eye follows the lines; it does not measure margins.
 *
 * ── The direction ────────────────────────────────────────────────────────────────────────────
 *
 * The rail is built from logical properties (`border-s`, `start-*`) so it mirrors with the page,
 * and the chevron points the way the reader's language runs — `ChevronLeft` in Arabic, because a
 * right-to-left reader opening a branch expects it to point into the text, not away from it.
 * Getting this wrong is the kind of thing that looks like a rendering bug rather than a choice.
 */
export function TreeRail({
    depth,
    isLast,
    hasChildren,
    collapsed,
    onToggle,
    rtl,
    label,
}: {
    /** 1 for a root node. */
    depth: number;
    /** Last child of its parent: the elbow stops here rather than running on down the page. */
    isLast: boolean;
    hasChildren: boolean;
    collapsed: boolean;
    onToggle: () => void;
    rtl: boolean;
    /** The node's name, for the toggle's accessible label. */
    label: string;
}) {
    const t = useT();
    const levels = Math.max(0, depth - 1);
    const Chevron = rtl ? ChevronLeft : ChevronRight;

    return (
        <div className="flex shrink-0 self-stretch" aria-hidden={hasChildren ? undefined : "true"}>
            {/* One column per ANCESTOR. Each draws the line that says "this branch continues". */}
            {Array.from({ length: levels }, (_, level) => (
                <span
                    key={level}
                    className={cn(
                        // 28 px per level (§2.9): 20 px was real indentation nobody could see
                        // against a full-width bordered card. The rows are one line now, so the
                        // step has to carry the depth on its own.
                        "relative w-7",
                        // The last column is the node's OWN elbow and stops half way down; the
                        // ones before it belong to ancestors and run the full height.
                        level === levels - 1 && isLast ? "" : "before:absolute before:inset-y-0 before:start-3.5 before:w-px before:bg-border",
                    )}
                >
                    {level === levels - 1 ? (
                        <>
                            {/* the elbow: down to the middle, then across into the row */}
                            {isLast ? (
                                <span className="absolute inset-y-0 top-0 start-3.5 h-1/2 w-px bg-border" />
                            ) : null}
                            <span className="absolute top-1/2 start-3.5 h-px w-3.5 bg-border" />
                        </>
                    ) : null}
                </span>
            ))}

            {/* The toggle sits where the branch opens, so the eye is already looking at it. */}
            <span className="flex w-6 items-center justify-center">
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-expanded={!collapsed}
                        aria-label={
                            collapsed
                                ? t("categories.expand_node", "افتح :name", { name: label })
                                : t("categories.collapse_node", "اطوِ :name", { name: label })
                        }
                        className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <Chevron
                            className={cn(
                                "h-4 w-4 transition-transform",
                                // Rotating rather than swapping the icon: the motion says "this
                                // one opened" in a way a different glyph does not.
                                collapsed ? "" : "rotate-90",
                            )}
                        />
                    </button>
                ) : (
                    // A leaf gets the same width, so every row at one depth starts in one place.
                    <span className="h-4 w-4" />
                )}
            </span>
        </div>
    );
}
