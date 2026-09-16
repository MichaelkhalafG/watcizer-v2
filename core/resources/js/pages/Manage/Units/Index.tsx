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
import { Select } from "@/components/ui/input";
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
    pre_switch_notice: { pre_switch: boolean; message: string } | null;
}

export default function UnitsIndex({ units, pre_switch_notice }: Props) {
    const t = useT();
    const [mergeFrom, setMergeFrom] = useState<Unit | null>(null);
    const [mergeInto, setMergeInto] = useState<string>("");

    /** The eight columns, in the operator's words rather than the schema's. */
    const COLUMN_LABELS: Record<string, string> = {
        band_length_unit_id: t("units.column_band_length", "طول السوار"),
        band_width_unit_id: t("units.column_band_width", "عرض السوار"),
        case_size_unit_id: t("units.column_case_size", "قياس العلبة"),
        case_thickness_unit_id: t(
            "units.column_case_thickness",
            "سماكة العلبة",
        ),
        height_unit_id: t("units.column_height", "الارتفاع"),
        length_unit_id: t("units.column_length", "الطول"),
        water_resistance_unit_id: t(
            "units.column_water_resistance",
            "مقاومة الماء",
        ),
        width_unit_id: t("units.column_width", "العرض"),
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
                {pre_switch_notice ? (
                    <div
                        className="rounded-lg border border-amber-300/60 bg-amber-50/60 p-4 text-sm leading-relaxed dark:border-amber-900/50 dark:bg-amber-950/20"
                        role="note"
                    >
                        {pre_switch_notice.message}
                    </div>
                ) : null}

                <div className="rounded-lg border bg-card p-4 text-sm leading-relaxed">
                    <p>
                        {t(
                            "units.intro",
                            "هذه القائمة جاءت من النظام القديم، وفيها وحدات قياس حقيقية (مم، سم، ATM) ومقاسات ملابس وأحذية (XS، XL، 26…47) مختلطة في مكان واحد. المقاسات ليست وحدات قياس، ووجودها في القائمة جعل بعض المواصفات تُسجَّل بوحدة خاطئة.",
                        )}
                    </p>
                    <p className="mt-2">
                        <strong>
                            {t(
                                "units.merge_first_heading",
                                "ادمج أولًا، ثم أخرج من القوائم.",
                            )}
                        </strong>{" "}
                        {t(
                            "units.merge_first_body",
                            "الدمج ينقل كل المواصفات من وحدة إلى أخرى ثم يُحيل القديمة للتقاعد. الإخراج وحده مرفوض ما دامت الوحدة مستخدمة — وإلا بقيت مواصفات مرتبطة بوحدة لا يراها أحد. لا شيء يُحذف نهائيًا، والإخراج قابل للتراجع.",
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
                                <TableHead>
                                    {t("units.usage", "الاستخدام")}
                                </TableHead>
                                <TableHead>{t("units.where", "أين")}</TableHead>
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
                                            {unit.looks_like_a_size ? (
                                                <Badge
                                                    variant="warning"
                                                    title={t(
                                                        "units.looks_like_a_size_hint",
                                                        "هذا يبدو مقاس ملابس أو حذاء، وليس وحدة قياس. جاء من جدول المقاسات القديم.",
                                                    )}
                                                >
                                                    {t(
                                                        "units.looks_like_a_size",
                                                        "يبدو مقاسًا",
                                                    )}
                                                </Badge>
                                            ) : null}
                                            {unit.retired_at !== null ? (
                                                <Badge variant="neutral">
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
                                        {unit.used === 0 ? (
                                            <span className="text-muted-foreground">
                                                {t("common.none", "لا يوجد")}
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
                                                    ":count مواصفة",
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
                                                                "ادمجها أولًا",
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

                {mergeFrom !== null ? (
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">
                            {t(
                                "units.merge_heading",
                                "دمج :code في وحدة أخرى",
                                { code: mergeFrom.code },
                            )}
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {mergeFrom.used === 0
                                ? t(
                                      "units.merge_unused_note",
                                      "هذه الوحدة غير مستخدمة، وسيقتصر الأمر على إحالتها للتقاعد.",
                                  )
                                : t(
                                      "units.merge_used_note",
                                      "سيتم نقل :count مواصفة إلى الوحدة التي تختارها، ثم تُحال هذه الوحدة للتقاعد.",
                                      { count: mergeFrom.used },
                                  )}
                        </p>

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <Select
                                aria-label={t(
                                    "units.target_unit",
                                    "الوحدة الهدف",
                                )}
                                className="w-64"
                                value={mergeInto}
                                onChange={(event) =>
                                    setMergeInto(event.target.value)
                                }
                            >
                                <option value="">
                                    {t(
                                        "units.choose_target_unit",
                                        "اختر الوحدة الهدف…",
                                    )}
                                </option>
                                {targets.map((unit) => (
                                    <option
                                        key={unit.id}
                                        value={String(unit.id)}
                                    >
                                        {unit.code} — {unit.name_ar}
                                        {unit.used > 0 ? ` (${unit.used})` : ""}
                                    </option>
                                ))}
                            </Select>

                            <Button
                                onClick={submitMerge}
                                disabled={mergeInto === ""}
                            >
                                {t("units.run_merge", "تنفيذ الدمج")}
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setMergeFrom(null)}
                            >
                                {t("common.cancel", "إلغاء")}
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>
        </ManageLayout>
    );
}
