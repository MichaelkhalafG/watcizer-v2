import { router } from "@inertiajs/react";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { SelectField } from "@/components/form/TextField";
import ManageLayout from "@/layouts/ManageLayout";
import { ExportLink } from "@/components/table/ExportLink";
import { useT } from "@/lib/i18n";

/**
 * Units cleanup (wave 4D, task C3).
 *
 * ── What the admin is being shown, and why it is not an ordinary lookup list ─────────────────
 *
 * `catalog_units` came out of the legacy `size_types` table, which mixed physical units (mm, cm,
 * ATM) with garment and shoe SIZES (XS…XXXXXL, 26…47). The watch form's unit picker therefore
 * offers thirty-one wrong answers, and they have been taken: `M` is the recorded unit of 231 watch
 * measurements. Editing a name would not fix that; only MOVING the measurements does.
 *
 * So this screen has two verbs and they are ordered:
 *
 *   1. **دمج (merge)** — move every measurement from one unit onto another, then retire the empty
 *      one. This is the one that repairs data.
 *   2. **إخراج من القوائم (retire)** — hide an UNUSED unit. The server refuses while anything still
 *      points at it, which is what forces step 1 first.
 *
 * Nothing here deletes, and the screen says so: every unit id is referenced by a RESTRICT foreign
 * key and preserved by the transform, so hiding is the reversible operation and deleting is not
 * available at all.
 */

interface Unit {
    id: number;
    code: string;
    name_ar: string;
    name_en: string;
    retired_at: string | null;
    used: number;
    used_by: Record<string, number>;
    looks_like_a_size: boolean;
}

interface Props {
    units: Unit[];
    columns: string[];
}

export default function UnitsIndex({ units }: Props) {
    const t = useT();
    const [mergeFrom, setMergeFrom] = useState<Unit | null>(null);
    const [mergeInto, setMergeInto] = useState<string>("");

    /** The eight columns, in the operator's words rather than the schema's. */
    const COLUMN_LABELS: Record<string, string> = {
        band_length_unit_id: t("units.column_band_length", "طول السوار"),
        band_width_unit_id: t("units.column_band_width", "عرض السوار"),
        case_size_unit_id: t("units.column_case_size", "قياس جسم الساعة"),
        case_thickness_unit_id: t(
            "units.column_case_thickness",
            "سماكة جسم الساعة",
        ),
        height_unit_id: t("units.column_height", "ارتفاع الساعة"),
        length_unit_id: t("units.column_length", "طول الساعة"),
        water_resistance_unit_id: t(
            "units.column_water_resistance",
            "مقاومة الماء",
        ),
        width_unit_id: t("units.column_width", "عرض الساعة"),
    };

    // A merge target must be a unit that is staying: never itself, never one on its way out.
    const targets = units.filter(
        (unit) => unit.retired_at === null && unit.id !== mergeFrom?.id,
    );

    const submitMerge = () => {
        if (mergeFrom === null || mergeInto === "") {
            return;
        }
        router.post(
            "/manage/units/merge",
            { from: mergeFrom.id, into: Number(mergeInto) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMergeFrom(null);
                    setMergeInto("");
                },
            },
        );
    };

    return (
        <ManageLayout
            title={t("units.title", "وحدات القياس")}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("units.title", "وحدات القياس") },
            ]}
            actions={<ExportLink count={units.length} />}
        >
            <div className="space-y-4">
                {/* ── Written for the person entering products (2026-10-05) ─────────────

                    The developer: *"the explanation above the table is written in system logic,
                    not in the team's language."* It was — «يُحيل القديمة للتقاعد»,
                    «الإخراج وحده مرفوض ما دامت الوحدة مستخدمة» — sentences that only parse
                    if you already know there is a table with a foreign key in it.

                    What replaces it answers four questions in order: what this screen is, why it
                    is wrong, what each button does TO THEIR PRODUCTS, and which button to press
                    today. With the example, because «40 M» instead of «40 مم» is the whole
                    problem in four characters. */}
                <div className="space-y-2 rounded-lg border bg-card p-4 text-sm leading-relaxed">
                    <p>
                        {t(
                            "units.intro",
                            "هذه هي قائمة الوحدات التي تظهر لك في خانة «الوحدة» وأنت تكتب مواصفات منتج — مم، سم، جرام، ATM.",
                        )}
                    </p>
                    <p>
                        {t(
                            "units.intro_problem",
                            "دخلت في القائمة مقاسات ملابس وأحذية مثل M وXL و42، وهي ليست وحدات قياس. ولأنها ظهرت في نفس الخانة، اختارها أحدهم بالغلط: مكتوب الآن على منتجات «قياس جسم الساعة: 40 M» والمقصود «40 مم».",
                        )}
                    </p>
                    <p>
                        <strong>
                            {t("units.intro_merge_heading", "زر «دمج» يُصلّح المنتجات.")}
                        </strong>{" "}
                        {t(
                            "units.intro_merge_body",
                            "تختار الوحدة الغلط، ثم تختار الوحدة الصحيحة، فتتحوّل كل المواصفات المكتوبة بالأولى إلى الثانية دفعة واحدة. لا تفتح منتجًا واحدًا، ولا تتغير الأرقام نفسها — يتغير اسم الوحدة بجانبها فقط.",
                        )}
                    </p>
                    <p>
                        <strong>
                            {t("units.intro_retire_heading", "زر «إخراج من القوائم» يمنع تكرار الغلطة.")}
                        </strong>{" "}
                        {t(
                            "units.intro_retire_body",
                            "الوحدة تختفي من خانة «الوحدة» فلا يختارها أحد بعد اليوم. المنتجات القديمة لا يحدث لها شيء، والوحدة ترجع بضغطة واحدة لو أخطأت.",
                        )}
                    </p>
                    <p className="text-muted-foreground">
                        {t(
                            "units.intro_which_button",
                            "أي زر تضغط؟ لو أمام الوحدة رقم في عمود «مكتوبة في» فهي موجودة على منتجات الآن — ادمجها. لو مكتوب «لا أحد يستخدمها» فلا داعي للدمج — أخرجها من القوائم مباشرة. لا شيء هنا يُحذف، وكل خطوة يمكن التراجع عنها.",
                        )}
                    </p>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader className="bg-muted/50">
                            <tr>
                                <TableHead>
                                    {t("common.unit", "الوحدة")}
                                </TableHead>
                                {/* «الاستخدام» and «أين» named what the system had counted.
                                    These name what the operator is looking at: where the unit is
                                    written, and in which box on the product form. */}
                                <TableHead>
                                    {t("units.written_in", "مكتوبة في")}
                                </TableHead>
                                <TableHead>
                                    {t("units.in_which_box", "في أي خانة")}
                                </TableHead>
                                <TableHead>
                                    {t("common.actions", "إجراءات")}
                                </TableHead>
                            </tr>
                        </TableHeader>
                        <TableBody>
                            {units.map((unit) => (
                                <TableRow key={unit.id} className="align-top">
                                    <TableCell className="py-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span
                                                className="font-medium"
                                                dir="ltr"
                                            >
                                                {unit.code}
                                            </span>
                                            {/* «يبدو مقاسًا» was an observation. This is the
                                                instruction that follows from it. */}
                                            {unit.looks_like_a_size ? (
                                                <Badge
                                                    variant="warning"
                                                    className="whitespace-nowrap"
                                                    title={t(
                                                        "units.looks_like_a_size_hint",
                                                        "مقاس ملابس أو حذاء دخل بالغلط في قائمة وحدات القياس. ادمجه في الوحدة الصحيحة — المقصود غالبًا «مم» — وستُصلّح المواصفات المكتوبة به.",
                                                    )}
                                                >
                                                    {t(
                                                        "units.looks_like_a_size",
                                                        "مقاس وليس وحدة — ادمجه",
                                                    )}
                                                </Badge>
                                            ) : null}
                                            {unit.retired_at !== null ? (
                                                <Badge
                                                    variant="neutral"
                                                    className="whitespace-nowrap"
                                                    title={t(
                                                        "units.retired_hint",
                                                        "لا تظهر في خانة الوحدة عند كتابة منتج. المنتجات التي تستخدمها لم يتغير فيها شيء.",
                                                    )}
                                                >
                                                    {t(
                                                        "units.retired",
                                                        "خارج القوائم",
                                                    )}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {unit.name_ar}
                                            {unit.name_en !== "" &&
                                            unit.name_en !== unit.name_ar
                                                ? ` — ${unit.name_en}`
                                                : ""}
                                        </div>
                                    </TableCell>

                                    <TableCell className="py-3">
                                        {/* «244 مواصفة» was a count with no verb attached.
                                            A number on this screen is only ever read to answer
                                            one question — *can I take this out, or do I have to
                                            merge it first?* — so the cell answers that. */}
                                        {unit.used === 0 ? (
                                            <span className="text-muted-foreground">
                                                {t(
                                                    "units.nobody_uses_it",
                                                    "لا أحد يستخدمها",
                                                )}
                                            </span>
                                        ) : (
                                            <span
                                                className={
                                                    unit.looks_like_a_size
                                                        ? "font-medium text-destructive"
                                                        : "font-medium"
                                                }
                                            >
                                                {t(
                                                    "units.spec_count",
                                                    ":count مواصفة منتج مكتوبة بها",
                                                    { count: unit.used },
                                                )}
                                            </span>
                                        )}
                                    </TableCell>

                                    <TableCell className="py-3 text-xs text-muted-foreground">
                                        {Object.entries(unit.used_by).map(
                                            ([column, count]) => (
                                                <div key={column}>
                                                    {COLUMN_LABELS[column] ??
                                                        column}
                                                    : {count}
                                                </div>
                                            ),
                                        )}
                                    </TableCell>

                                    <TableCell className="py-3">
                                        <div className="flex flex-wrap gap-2">
                                            {unit.retired_at === null ? (
                                                <>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => {
                                                            setMergeFrom(unit);
                                                            setMergeInto("");
                                                        }}
                                                    >
                                                        {t(
                                                            "units.merge_into_another",
                                                            "دمج في وحدة أخرى",
                                                        )}
                                                    </Button>
                                                    {unit.used === 0 ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/manage/units/${unit.id}/retire`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                "units.retire",
                                                                "إخراج من القوائم",
                                                            )}
                                                        </Button>
                                                    ) : (
                                                        <span
                                                            className="self-center text-xs text-muted-foreground"
                                                            title={t(
                                                                "units.cannot_retire_hint",
                                                                "لا يمكن إخراجها وهي مستخدمة: ادمجها في وحدة أخرى أولًا.",
                                                            )}
                                                        >
                                                            {t(
                                                                "units.merge_it_first",
                                                                "مكتوبة على منتجات — ادمجها أولًا",
                                                            )}
                                                        </span>
                                                    )}
                                                </>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/manage/units/${unit.id}/restore`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        "units.restore",
                                                        "إعادة إلى القوائم",
                                                    )}
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {/* ── The merge button used to do nothing visible (2026-10-05) ───────────

                    Reported as dead, and it was not: `setMergeFrom` ran, the panel rendered, and
                    it rendered as the NEXT SIBLING OF THE TABLE — measured in the browser at
                    **1,967px below the fold** on a 37-row list, with no scroll, no focus move and
                    no other change on screen. From the operator's seat a button that draws
                    something two screens down has done nothing, and they were right to report it
                    as broken.

                    A dialog is the fix, and it is the fix rather than a `scrollIntoView` for three
                    reasons: it appears where the person is looking whatever the row; it takes
                    focus, so a keyboard user lands in the control they just asked for; and it is
                    the shape this dashboard already uses for "choose something, then confirm"
                    (the category tree's edit and move, every ConfirmAction). A scroll would have
                    fixed the symptom on this screen and left the pattern wrong. */}
                <Dialog
                    open={mergeFrom !== null}
                    onOpenChange={(open) => (open ? null : setMergeFrom(null))}
                >
                    {mergeFrom === null ? null : (
                        <DialogContent
                            title={t(
                                "units.merge_heading",
                                "دمج :code في وحدة أخرى",
                                { code: mergeFrom.code },
                            )}
                            description={
                                mergeFrom.used === 0
                                    ? t(
                                          "units.merge_unused_note",
                                          "لا توجد مواصفات مكتوبة بهذه الوحدة، فلن يتغير شيء على أي منتج — ستخرج من القوائم فقط.",
                                      )
                                    : t(
                                          "units.merge_used_note",
                                          ":count مواصفة منتج مكتوبة بهذه الوحدة ستُكتب بالوحدة التي تختارها. الأرقام نفسها لا تتغير، ثم تخرج هذه الوحدة من القوائم.",
                                          { count: mergeFrom.used },
                                      )
                            }
                        >
                            <div className="space-y-4">
                                <SelectField
                                    label={t(
                                        "units.target_unit",
                                        "الوحدة الصحيحة",
                                    )}
                                    hint={t(
                                        "units.target_unit_hint",
                                        "الوحدة التي كان المفروض كتابة هذه المواصفات بها. الرقم بجانب كل وحدة هو عدد المواصفات المكتوبة بها الآن.",
                                    )}
                                    placeholder={t(
                                        "units.choose_target_unit",
                                        "اختر الوحدة الصحيحة…",
                                    )}
                                    options={targets.map((unit) => ({
                                        value: String(unit.id),
                                        label:
                                            `${unit.code} — ${unit.name_ar}` +
                                            (unit.used > 0 ? ` (${unit.used})` : ""),
                                    }))}
                                    value={mergeInto}
                                    onChange={setMergeInto}
                                />

                                <div className="flex flex-wrap justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => setMergeFrom(null)}
                                    >
                                        {t("common.cancel", "إلغاء")}
                                    </Button>
                                    <Button
                                        onClick={submitMerge}
                                        disabled={mergeInto === ""}
                                    >
                                        {t("units.run_merge", "نفّذ الدمج")}
                                    </Button>
                                </div>
                            </div>
                        </DialogContent>
                    )}
                </Dialog>
            </div>
        </ManageLayout>
    );
}
