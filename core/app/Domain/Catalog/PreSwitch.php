<?php

namespace App\Domain\Catalog;

use App\Console\Commands\CoreChecksumCommand;
use App\Models\Storefront\Storefront;
use App\Support\ManageText;
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
     * The operator-facing NAME of each action is not here but in {@see self::label()}: a `const`
     * cannot call the translation seam, and that name is read by a member of staff — in their own
     * language — while `why` below is a note for whoever maintains this list.
     *
     * @var array<string, array{tables: list<string>, blocked: bool, why: string}>
     */
    public const CREATIONS = [
        'product' => [
            'tables' => ['catalog_products', 'catalog_product_translations', 'catalog_product_search'],
            'blocked' => true,
            'why' => 'A product typed here has no legacy source, so the rebuild deletes it and the work with it. '
                .'This is the action the decision named.',
        ],
        'variant' => [
            'tables' => ['catalog_product_variants'],
            'blocked' => true,
            'why' => 'Legacy `product_variants` is empty, so every dashboard variant is deleted by the rebuild — '
                .'and its ledger movements are re-levelled onto the product (AGENTS §2.22). '
                .'A live product was already refused by ConversionGuard; this closes the rest.',
        ],
        'category' => [
            'tables' => ['storefront_categories', 'storefront_category_translations'],
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
            'blocked' => true,
            'why' => 'A new colour/size/brand is deleted by the rebuild, and anything the team pointed at it loses '
                .'the reference. The lists come from legacy until the switch.',
        ],

        // ── Allowed, deliberately. Each is a property of a row that already exists, so the
        //    rebuild REVERTS it rather than deleting an entity — and each is something the team
        //    must be able to practise before they are asked to do it for real on switch night.
        'product_image' => [
            'tables' => ['catalog_product_images'],
            'blocked' => false,
            'why' => 'An image is a property of a product that already exists, and "upload, reorder, choose the '
                .'cover" is a skill the team has to practise. The rebuild restores the legacy gallery.',
        ],
        'placement' => [
            'tables' => ['storefront_category_product'],
            'blocked' => false,
            'why' => 'Placement is data-entry\'s core job (§2.7) and the transform recreates the row from legacy, '
                .'so the effect is a revert. A placement inside a dashboard-created category cannot exist, '
                .'because creating the category is blocked.',
        ],
        'storefront_product' => [
            'tables' => ['storefront_product'],
            'blocked' => false,
            'why' => 'The transform writes one row per product per storefront, so this is a revert and not a '
                .'deletion. Visibility, order, featured and slug are the placement screen\'s whole purpose.',
        ],
        'watch_specs' => [
            'tables' => ['catalog_product_watch_specs'],
            'blocked' => false,
            'why' => 'One row per product, written by the product form for a product that already exists.',
        ],
        'redirect' => [
            'tables' => ['storefront_redirects'],
            'blocked' => false,
            'why' => 'A consequence of an allowed edit, never typed directly. Blocking it would leave a renamed '
                .'slug with no redirect, which is worse than a row the rebuild replaces. Pre-switch it is '
                .'unreachable anyway, because slug EDITING is locked until the flag flips '
                .'(see self::mayEditSlug()): the rebuild deletes the redirect along with the slug that '
                .'produced it.',
        ],
        'attributes' => [
            // The three pure pivots, added after review 🟡-4 found them MISSING from this map. Their
            // absence made `nonTransformTables()` pass for the wrong reason: a table the UI writes
            // was simply not declared, so the "complete declared list" was not complete. Allowed for
            // the same reason as the other four — each row is a property of a product that already
            // exists, so a rebuild REVERTS the set rather than deleting an entity.
            'tables' => ['catalog_product_feature', 'catalog_product_gender', 'catalog_product_color'],
            'blocked' => false,
            'why' => 'Features, genders and colour roles are attribute SETS on a product that already exists, '
                .'written by the product form as replace-in-place. The transform rebuilds them from legacy, so '
                .'the effect of a rebuild is a revert and never a deletion.',
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
     * Its operator-facing name lives in {@see self::secondaryTreeLabel()}, for the reason given on
     * {@see self::CREATIONS}: a `const` cannot reach the translation seam.
     *
     * @var array{why: string}
     */
    public const SECONDARY_TREE = [
        'why' => 'Until the write-switch the tree of every storefront but the primary is RE-SYNCED from legacy on '
            .'every transform run, because the team is still authoring categories in the legacy dashboard and '
            .'Brand Fashion must not fall behind. A rename or a move made here would be overwritten by the next '
            .'run, so it is refused instead of silently lost. The sync stops permanently when the flag flips.',
    ];

    /**
     * The one exemption name that is not a creation: editing a NON-PRIMARY storefront's category
     * tree, which is a mirror of legacy until the switch. An importer that adds nodes to Brand
     * Fashion must name it alongside `category`, so what it opens is written down in the call.
     */
    public const SECONDARY_TREE_EDIT = 'secondary_tree';

    /**
     * What a creation is CALLED, for the person reading the refusal.
     *
     * It is a method and not a `label` key of {@see self::CREATIONS} because a class constant
     * cannot call {@see ManageText::t()}, and this is the one part of the declaration an operator
     * actually reads — `why` beside it is a note for whoever maintains the list and stays English.
     *
     * The `match` is exhaustive over the declared actions on purpose: an action added to
     * `CREATIONS` without a name here throws the moment a screen tries to render it, rather than
     * showing the team a blank where the thing they were refused should be.
     */
    public static function label(string $action): string
    {
        return match ($action) {
            // `products.new_title` is the product form's own heading, and this is the same words on
            // the same screen — reusing the key is what stops the two drifting apart in English.
            'product' => ManageText::t('products.new_title', 'منتج جديد'),
            'variant' => ManageText::t('products.creation_variant', 'صف مقاس/لون جديد'),
            'category' => ManageText::t('categories.new_title', 'تصنيف جديد'),
            'lookup' => ManageText::t('products.creation_lookup_item', 'عنصر جديد في قائمة مرجعية'),
            'product_image' => ManageText::t('products.creation_product_image', 'صورة لمنتج قائم'),
            'placement' => ManageText::t('products.creation_placement', 'ربط منتج بتصنيف'),
            'storefront_product' => ManageText::t('products.creation_storefront_product', 'إضافة منتج قائم إلى متجر'),
            'watch_specs' => ManageText::t('products.creation_watch_specs', 'مواصفات ساعة لمنتج قائم'),
            'redirect' => ManageText::t('products.creation_redirect', 'تحويل 301 بعد تغيير رابط'),
            'attributes' => ManageText::t('products.creation_attributes', 'خصائص وفئات وألوان منتج قائم'),
            default => throw new RuntimeException(
                "PreSwitch has no operator-facing label for the creation [{$action}]. Add one to "
                .'PreSwitch::label() beside its entry in PreSwitch::CREATIONS.'
            ),
        };
    }

    /** What a non-primary storefront's mirrored category tree is called, for the same reason. */
    public static function secondaryTreeLabel(): string
    {
        return ManageText::t('products.secondary_tree_label', 'شجرة تصنيفات متجر آخر');
    }

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
        return $storefrontId === Storefront::WATCHIZER_ID
            || isset(self::$exempt[self::SECONDARY_TREE_EDIT])
            || ! self::syncsSecondaryTrees();
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

    /**
     * May the dashboard CHANGE a product's slug yet?
     *
     * ── The contradiction this closes (review 🟠-3) ──────────────────────────────────────────
     *
     * The slug field promised a permanent redirect: "تغييره ينشئ تحويلًا 301 من الرابط القديم".
     * Before the write-switch that promise cannot be kept, and not approximately —
     * `storefront_product` and `storefront_redirects` are BOTH transform output, so the next
     * rebuild deletes the typed slug AND the 301 that was written to protect it. The operator
     * would have done careful work, been told a redirect exists, and been left with neither.
     *
     * A caveat at the field was the other option and was rejected: §2.27 says a rule that really is
     * a rule is enforced in the form rather than explained next to it, and "this will be undone" is
     * not a caveat — it is a refusal in a softer voice. So the field is LOCKED until the flag flips,
     * which costs the team nothing: the transform derives every slug from the legacy EN title on
     * insert, so a hand-typed one was always transient.
     *
     * Everything else on the placement screen stays editable. Visibility, order, featured and the
     * category set are reverted by a rebuild too, but reverting a DECISION is recoverable by making
     * it again, while a slug change leaves a broken promise behind it — which is exactly the line
     * §2.23 draws between an edit and a creation.
     */
    public static function mayEditSlug(): bool
    {
        return self::completed();
    }

    /**
     * @throws RuntimeException
     */
    public static function assertMayEditSlug(): void
    {
        if (self::mayEditSlug()) {
            return;
        }

        // Declares its field, so the screen puts it beside the slug box rather than beside the
        // visibility toggle ({@see FieldRefusal}).
        throw new FieldRefusal('slug', self::slugMessage());
    }

    /** The refusal for a slug change, in the dashboard's language. */
    public static function slugMessage(): string
    {
        return ManageText::t(
            'products.slug_locked_pre_switch',
            'تغيير الروابط موقوف حتى ليلة التحويل: الرابط وتحويل 301 الذي يُنشأ معه يُعاد بناؤهما من النظام القديم في كل تحديث، فلو غيّرته الآن ستفقد الرابط الجديد والتحويل معه ويعود الرابط القديم بلا تحويل. غيّر الروابط من الداشبورد القديم حتى التحويل، أو من هنا بعده.',
        );
    }

    /**
     * What a screen needs to render its slug field honestly.
     *
     * @return array{write_switch_completed: bool, blocked: bool, message: string|null, label: string}
     */
    public static function slugState(): array
    {
        $blocked = ! self::mayEditSlug();

        return [
            'write_switch_completed' => self::completed(),
            'blocked' => $blocked,
            'message' => $blocked ? self::slugMessage() : null,
            // The same key the slug FIELD carries on the form, so the lock and the box it locks
            // cannot end up with two different names.
            'label' => ManageText::t('common.slug', 'الرابط (slug)'),
        ];
    }

    /**
     * The banner a catalogue screen shows before the write-switch, worded for WHAT THAT SCREEN
     * actually loses (review 🟠-3).
     *
     * One generic sentence used to serve every screen, and on the placement screen it was close to
     * useless: it spoke about "any product or edit" while the work a placement operator does is
     * categories, visibility, order, featured and slugs — each of which is recreated from legacy on
     * the next rebuild, and none of which the sentence named. An operator who cannot tell whether
     * their afternoon survives will assume that it does.
     *
     * @return array{pre_switch: bool, message: string}|null null once the switch has happened
     */
    public static function noticeFor(string $screen): ?array
    {
        if (self::completed()) {
            return null;
        }

        /*
         * The sentence both notices share stays ONE string with ONE key, spliced in as `:rebuild`
         * rather than concatenated: two screens saying the same thing about the rebuild must not
         * become two English sentences that drift.
         */
        $rebuild = ManageText::t(
            'products.pre_switch_rebuild',
            'جداول الكتالوج تُبنى من النظام القديم في كل تجربة وفي ليلة التحويل (core:drop-clean ثم migrate ثم core:transform).',
        );

        $message = match ($screen) {
            'placement' => ManageText::t(
                'products.pre_switch_notice_placement',
                'قبل ليلة التحويل: :rebuild كل ما تضبطه في هذه الشاشة يُعاد بناؤه من النظام القديم: ربط المنتجات بالتصنيفات، وقرارات الإظهار والإخفاء، والترتيب، والتمييز، والروابط المكتوبة يدويًا وتحويلات 301 التي أُنشئت معها. استخدم الشاشة للتدريب، واضبط العرض الحقيقي من الداشبورد القديم حتى التحويل. (تغيير الروابط موقوف أصلًا لأن تحويل 301 لا ينجو من إعادة البناء.)',
                ['rebuild' => $rebuild],
            ),
            default => ManageText::t(
                'products.pre_switch_notice',
                'قبل ليلة التحويل: :rebuild فأي منتج أو تعديل يُكتب هنا الآن يُستبدل بما في النظام القديم. استخدم هذه الشاشات للتدريب، وأدخل البيانات الحقيقية من الداشبورد القديم حتى التحويل.',
                ['rebuild' => $rebuild],
            ),
        };

        return ['pre_switch' => true, 'message' => $message];
    }

    /** The refusal for a secondary tree, in the dashboard's language. */
    public static function treeMessage(): string
    {
        return ManageText::t(
            'products.secondary_tree_mirrored',
            'شجرة تصنيفات هذا المتجر مرآة لشجرة واتشيزر حتى ليلة التحويل: كل إضافة أو إعادة تسمية أو نقل تتم في الداشبورد القديم وتنتقل تلقائيًا إلى هنا في كل تحديث. لو عدّلنا الشجرة من هنا الآن سيُلغى التعديل في التحديث التالي. بعد ليلة التحويل تصبح شجرة هذا المتجر ملكًا للفريق وتنفصل تمامًا عن شجرة واتشيزر، ولا يُعاد مزامنتهما أبدًا.',
        );
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
            'label' => self::secondaryTreeLabel(),
        ];
    }

    /**
     * Creations exempted for the duration of ONE call, by a caller that said so out loud.
     *
     * @var array<string, true>
     */
    private static array $exempt = [];

    /**
     * Run $work with the named creations allowed, because the OPERATOR asked for it on the command
     * line — the wave-4D importer rehearsal (developer, 2026-09-14).
     *
     * ── Why this exists, and why it is shaped like this ──────────────────────────────────────
     *
     * The importer's whole purpose is to create products before the write-switch: *"the entire
     * point is to exercise creation now so switch night holds no surprises."* The gate must
     * therefore open — and the developer's condition was that it open **explicitly and
     * reversibly, never as a permanent hole**.
     *
     * So it is not a config key (a config key is edited once and forgotten, and `.env` files get
     * copied to production — the `ORDER_MAIL_INLINE` lesson), not a subclass, and not a
     * `blocked => false` in the table above. It is a scope:
     *
     *   • it lives only in this PHP process, and only inside the callable;
     *   • the caller names exactly which creations it wants, so "import a product" cannot quietly
     *     also mean "edit a secondary storefront's tree";
     *   • `finally` restores the previous state even when the import throws, so a failed run
     *     cannot leave the door open behind it;
     *   • nesting is safe, because the previous set is restored rather than cleared.
     *
     * **What the caller is accepting** is stated in one place, here, so the command can print it:
     * every row created under this exemption lives in a transform-output table and is DELETED by
     * the next `core:drop-clean` → `migrate` → `core:transform`. That is expected. It is a
     * rehearsal.
     *
     * @template T
     *
     * @param  list<string>  $actions  keys of {@see self::CREATIONS}
     * @param  callable(): T  $work
     * @return T
     */
    public static function allowing(array $actions, callable $work): mixed
    {
        $previous = self::$exempt;

        foreach ($actions as $action) {
            // Validates the name against the declared list: an exemption for a creation that does
            // not exist is a typo that would silently protect nothing. `secondary_tree` is the one
            // name that is not a creation — it is the mirror policy below, and an importer that
            // adds categories to Brand Fashion needs both.
            if ($action !== self::SECONDARY_TREE_EDIT) {
                self::definition($action);
            }
            self::$exempt[$action] = true;
        }

        try {
            return $work();
        } finally {
            self::$exempt = $previous;
        }
    }

    /**
     * The exemptions in force right now — for a command that wants to print what it opened.
     *
     * @return list<string>
     */
    public static function exempted(): array
    {
        return array_keys(self::$exempt);
    }

    /** Is this creation allowed right now? */
    public static function allows(string $action): bool
    {
        return self::completed()
            || isset(self::$exempt[$action])
            || ! self::definition($action)['blocked'];
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
        // Declared, or this is a write path that never announced itself.
        self::definition($action);

        return ManageText::t(
            'products.pre_switch_create_blocked',
            'ممنوع قبل ليلة التحويل: :label لا يمكن إنشاؤه من هذه اللوحة الآن. جداول الكتالوج تُبنى من النظام القديم في كل تجربة وفي ليلة التحويل (core:drop-clean ثم migrate ثم core:transform)، فالصف الذي يُنشأ هنا يُحذف مع إعادة البناء ويضيع معه العمل. أدخل البيانات الجديدة من الداشبورد القديم حتى التحويل؛ التعديل والتصفح والتدريب على هذه الشاشات مفتوح.',
            ['label' => self::label($action)],
        );
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
        // Declared, or this is a screen asking about an action nothing announced.
        self::definition($action);
        $blocked = ! self::allows($action);

        return [
            'write_switch_completed' => self::completed(),
            'blocked' => $blocked,
            'message' => $blocked ? self::message($action) : null,
            'label' => self::label($action),
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
     * @return array{tables: list<string>, blocked: bool, why: string}
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
