<?php

namespace App\Domain\Catalog;

use App\Console\Commands\CoreChecksumCommand;
use App\Models\Storefront\Storefront;
use RuntimeException;

/**
 * The one door for "may the dashboard CREATE this, before the write-switch?" — developer decision
 * 2026-09-11 on wave 4B's loud flag 1.
 *
 * ── What is actually at stake, corrected ─────────────────────────────────────────────────────
 *
 * Wave 4B's report said a product created here "breaks the transform's reconciliation and is
 * destroyed by the next rebuild". The second half is true and is the reason this class exists.
 * **The first half was stated too strongly** and is corrected here, because a decision was taken
 * partly on it:
 *
 *   • Switch night is `core:drop-clean` → `migrate` → `core:transform`, and the transform
 *     reconciles AFTER rebuilding from legacy. Every dashboard-authored row in a transform-output
 *     table is already gone when the reconciliation runs, so it cannot fail switch night.
 *   • The reconciliation is therefore a property of a FRESH REBUILD, not of a live database. It is
 *     emphatic about that: it asserts `storefront_product[visible] = all products visible` and
 *     `storefront_product[slug plan] = the plan on every row`, so merely HIDING a product or
 *     changing a slug — data-entry's daily work — makes it mismatch too. That is not a defect in
 *     either the reconciliation or the dashboard; it is what "compare the clean tables against
 *     legacy immediately after rebuilding them" means.
 *   • What a dashboard-created row DOES break is the reconciliation of an **additive** transform
 *     run — the rehearsal/local mode that does not drop first (§2.13). It appears as a surplus,
 *     the same signal §2.9.6 rule 2 describes for residue, now with a second cause.
 *
 * So the harm this class prevents is the one the developer named first: **an afternoon of typing
 * that the next rebuild silently deletes.**
 *
 * ── The line: CREATION is blocked, EDITING is not ────────────────────────────────────────────
 *
 * Both are lost at the next rebuild, but they are lost differently, and the difference is what
 * the team experiences:
 *
 *   • A created row VANISHES. The product, the category, the colour is simply not there any more,
 *     and neither is the work.
 *   • An edited row is REVERTED to what legacy says. The row is still there; a field went back.
 *     That is the documented training caveat (§2.9.6 rule 1 anticipates exactly this), and
 *     blocking it would leave the team nothing to train on — which the decision explicitly keeps
 *     open ("browsing/training stays open").
 *
 * {@see self::CREATIONS} is the complete, declared list of every UI action that creates a row in a
 * transform-output table, with its disposition and reason. It IS the documentation — the wave-4B
 * record generates its table from this constant, so the list and the behaviour cannot drift.
 *
 * ── One flag, and it is not a date ───────────────────────────────────────────────────────────
 *
 * `config('transform.write_switch_completed')` / `CORE_WRITE_SWITCH_COMPLETED`, the same flag
 * {@see ConversionGuard} reads. Not a date comparison: the switch happens when it happens, and a
 * date guess would either open the door early or keep it shut after the team has moved in. It
 * defaults to FALSE, so the failure mode of forgetting is a refusal rather than a silent loss.
 * Where it flips is step 3g of the switch-night runbook (study §3.4).
 */
final class PreSwitch
{
    /**
     * Every UI action that creates a row in a transform-output table.
     *
     * `blocked` is the developer's decision, per action, in one place. A future importer that
     * writes `catalog_products` must call {@see self::assertMayCreate()} with `product` — that is
     * what makes this the single door rather than a note in a docblock.
     *
     * @var array<string, array{tables: list<string>, label: string, blocked: bool, why: string}>
     */
    public const CREATIONS = [
        'product' => [
            'tables' => ['catalog_products', 'catalog_product_translations', 'catalog_product_search'],
            'label' => 'منتج جديد',
            'blocked' => true,
            'why' => 'A product typed here has no legacy source, so the rebuild deletes it and the work with it. '
                .'This is the action the decision named.',
        ],
        'variant' => [
            'tables' => ['catalog_product_variants'],
            'label' => 'صف مقاس/لون جديد',
            'blocked' => true,
            'why' => 'Legacy `product_variants` is empty, so every dashboard variant is deleted by the rebuild — '
                .'and its ledger movements are re-levelled onto the product (AGENTS §2.22). '
                .'A live product was already refused by ConversionGuard; this closes the rest.',
        ],
        'category' => [
            'tables' => ['storefront_categories', 'storefront_category_translations'],
            'label' => 'تصنيف جديد',
            'blocked' => true,
            'why' => 'A node with no legacy key is deleted by the rebuild, and every placement inside it goes with '
                .'it (ON DELETE CASCADE). Re-parenting the team did around it goes too.',
        ],
        'lookup' => [
            'tables' => [
                'catalog_brands', 'catalog_colors', 'catalog_sizes', 'catalog_materials', 'catalog_shapes',
                'catalog_movement_types', 'catalog_closure_types', 'catalog_display_types', 'catalog_units',
                'catalog_genders', 'catalog_features', 'catalog_grades',
            ],
            'label' => 'عنصر جديد في قائمة مرجعية',
            'blocked' => true,
            'why' => 'A new colour/size/brand is deleted by the rebuild, and anything the team pointed at it loses '
                .'the reference. The lists come from legacy until the switch.',
        ],

        // ── Allowed, deliberately. Each is a property of a row that already exists, so the
        //    rebuild REVERTS it rather than deleting an entity — and each is something the team
        //    must be able to practise before they are asked to do it for real on switch night.
        'product_image' => [
            'tables' => ['catalog_product_images'],
            'label' => 'صورة لمنتج قائم',
            'blocked' => false,
            'why' => 'An image is a property of a product that already exists, and "upload, reorder, choose the '
                .'cover" is a skill the team has to practise. The rebuild restores the legacy gallery.',
        ],
        'placement' => [
            'tables' => ['storefront_category_product'],
            'label' => 'ربط منتج بتصنيف',
            'blocked' => false,
            'why' => 'Placement is data-entry\'s core job (§2.7) and the transform recreates the row from legacy, '
                .'so the effect is a revert. A placement inside a dashboard-created category cannot exist, '
                .'because creating the category is blocked.',
        ],
        'storefront_product' => [
            'tables' => ['storefront_product'],
            'label' => 'إضافة منتج قائم إلى متجر',
            'blocked' => false,
            'why' => 'The transform writes one row per product per storefront, so this is a revert and not a '
                .'deletion. Visibility, order, featured and slug are the placement screen\'s whole purpose.',
        ],
        'watch_specs' => [
            'tables' => ['catalog_product_watch_specs'],
            'label' => 'مواصفات ساعة لمنتج قائم',
            'blocked' => false,
            'why' => 'One row per product, written by the product form for a product that already exists.',
        ],
        'redirect' => [
            'tables' => ['storefront_redirects'],
            'label' => 'تحويل 301 بعد تغيير رابط',
            'blocked' => false,
            'why' => 'A consequence of an allowed edit, never typed directly. Blocking it would leave a renamed '
                .'slug with no redirect, which is worse than a row the rebuild replaces.',
        ],
    ];

    /**
     * The SECONDARY-STOREFRONT TREE policy, declared here because it reads the same one flag and a
     * policy split across two files is a policy nobody can check.
     *
     * Brand Fashion's category tree is an INDEPENDENT COPY of Watchizer's: its own nodes under its
     * own `storefront_id`, with their own ids, paths and slugs, keyed to the same legacy origin
     * (`legacy_source`, `legacy_id`, `legacy_parent_id`). Never shared rows — deleting a category
     * on Brand Fashion can no more touch Watchizer than deleting a row in one table touches
     * another.
     *
     * @var array{label: string, why: string}
     */
    public const SECONDARY_TREE = [
        'label' => 'شجرة تصنيفات متجر آخر',
        'why' => 'Until the write-switch the tree of every storefront but the primary is RE-SYNCED from legacy on '
            .'every transform run, because the team is still authoring categories in the legacy dashboard and '
            .'Brand Fashion must not fall behind. A rename or a move made here would be overwritten by the next '
            .'run, so it is refused instead of silently lost. The sync stops permanently when the flag flips.',
    ];

    /** Has the write-switch happened? While false, legacy is still the system of record. */
    public static function completed(): bool
    {
        return (bool) config('transform.write_switch_completed', false);
    }

    /**
     * Does the transform still mirror the non-primary storefronts' category trees from legacy?
     *
     * **TRUE until the write-switch, FALSE for ever after** — the flag flip IS the transition, so
     * there is no separate runbook step to forget (study §3.4 step 3g).
     *
     * While TRUE: every addition, rename and re-parent the team makes in the LEGACY dashboard
     * propagates to every active storefront on the next run, and the dashboard refuses tree edits
     * on the non-primary storefronts ({@see self::assertMayEditTree()}) because they would be
     * overwritten. While FALSE: the trees diverge freely, the team owns each of them, and nothing
     * re-syncs them again — insert-only applies from that moment.
     */
    public static function syncsSecondaryTrees(): bool
    {
        return ! self::completed();
    }

    /**
     * May the dashboard EDIT this storefront's category tree right now?
     *
     * The primary storefront: always (its tree is what the team has always edited, and the
     * pre-switch caveat for an edit is a revert, not a loss — §2.9.6 rule 1). Any other storefront:
     * only once the sync has stopped.
     *
     * Note what this does NOT gate: the TRANSFORM's own writes. Those go through
     * `App\Transform\CategoryNodes`, never through `CategoryTreeWriter`, so the mirror cannot be
     * refused by the guard that exists to protect it.
     */
    public static function mayEditTree(int $storefrontId): bool
    {
        return $storefrontId === Storefront::WATCHIZER_ID || ! self::syncsSecondaryTrees();
    }

    /**
     * @throws RuntimeException
     */
    public static function assertMayEditTree(int $storefrontId): void
    {
        if (self::mayEditTree($storefrontId)) {
            return;
        }

        throw new RuntimeException(self::treeMessage());
    }

    /** The refusal for a secondary tree, in the dashboard's language. */
    public static function treeMessage(): string
    {
        return 'شجرة تصنيفات هذا المتجر مرآة لشجرة واتشيزر حتى ليلة التحويل: كل إضافة أو إعادة تسمية أو نقل '
            .'تتم في الداشبورد القديم وتنتقل تلقائيًا إلى هنا في كل تحديث. لو عدّلنا الشجرة من هنا الآن '
            .'سيُلغى التعديل في التحديث التالي. بعد ليلة التحويل تصبح شجرة هذا المتجر ملكًا للفريق وتنفصل تمامًا '
            .'عن شجرة واتشيزر، ولا يُعاد مزامنتهما أبدًا.';
    }

    /**
     * What the category screen needs to render itself honestly for ONE storefront.
     *
     * @return array{write_switch_completed: bool, blocked: bool, message: string|null, label: string}
     */
    public static function treeState(int $storefrontId): array
    {
        $blocked = ! self::mayEditTree($storefrontId);

        return [
            'write_switch_completed' => self::completed(),
            'blocked' => $blocked,
            'message' => $blocked ? self::treeMessage() : null,
            'label' => self::SECONDARY_TREE['label'],
        ];
    }

    /** Is this creation allowed right now? */
    public static function allows(string $action): bool
    {
        return self::completed() || ! self::definition($action)['blocked'];
    }

    /**
     * Refuse, with the sentence the team needs to act on.
     *
     * @throws RuntimeException
     */
    public static function assertMayCreate(string $action): void
    {
        if (self::allows($action)) {
            return;
        }

        throw new RuntimeException(self::message($action));
    }

    /** The refusal, in the dashboard's language and naming what to do instead. */
    public static function message(string $action): string
    {
        $definition = self::definition($action);

        return 'ممنوع قبل ليلة التحويل: '.$definition['label'].' لا يمكن إنشاؤه من هذه اللوحة الآن. '
            .'جداول الكتالوج تُبنى من النظام القديم في كل تجربة وفي ليلة التحويل '
            .'(core:drop-clean ثم migrate ثم core:transform)، فالصف الذي يُنشأ هنا يُحذف مع إعادة البناء '
            .'ويضيع معه العمل. أدخل البيانات الجديدة من الداشبورد القديم حتى التحويل؛ '
            .'التعديل والتصفح والتدريب على هذه الشاشات مفتوح.';
    }

    /**
     * What a screen needs to render itself honestly: whether the button works, and the sentence
     * to show instead of it. The server refuses again on the write path — hiding a control is
     * never the control.
     *
     * @return array{write_switch_completed: bool, blocked: bool, message: string|null, label: string}
     */
    public static function state(string $action): array
    {
        $definition = self::definition($action);
        $blocked = ! self::allows($action);

        return [
            'write_switch_completed' => self::completed(),
            'blocked' => $blocked,
            'message' => $blocked ? self::message($action) : null,
            'label' => $definition['label'],
        ];
    }

    /**
     * Every transform-output table the UI can insert into, and whether that insert is blocked.
     *
     * Used by the record in the study and by the test that keeps the two honest: every table named
     * in {@see self::CREATIONS} must really be transform output, or the list is describing
     * something else.
     *
     * @return array<string, array{action: string, blocked: bool, why: string}>
     */
    public static function tableMap(): array
    {
        $out = [];
        foreach (self::CREATIONS as $action => $definition) {
            foreach ($definition['tables'] as $table) {
                $out[$table] = ['action' => $action, 'blocked' => $definition['blocked'], 'why' => $definition['why']];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Tables the UI creates rows in that are NOT transform output — the list must stay empty.
     *
     * @return list<string>
     */
    public static function nonTransformTables(): array
    {
        return array_values(array_diff(array_keys(self::tableMap()), CoreChecksumCommand::CLEAN_TABLES));
    }

    /**
     * @return array{tables: list<string>, label: string, blocked: bool, why: string}
     */
    private static function definition(string $action): array
    {
        if (! isset(self::CREATIONS[$action])) {
            // A new write path that forgot to declare itself is a bug, not a pass: the whole point
            // of the list is that nothing creates a transform-output row without appearing in it.
            throw new RuntimeException(
                "PreSwitch has no entry for the creation [{$action}]. Declare it in PreSwitch::CREATIONS "
                .'with a disposition and a reason before writing a transform-output table from the UI.'
            );
        }

        return self::CREATIONS[$action];
    }
}
