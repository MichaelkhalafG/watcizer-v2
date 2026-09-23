<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\DashboardAccounts;
use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableExport;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Users and roles — the screen 4A promised (wave 4C, AGENTS §2.7).
 *
 * ── It CREATES accounts now, and that is a change of rule (2026-09-20) ───────────────────────
 *
 * This screen granted roles and nothing else, because `users` is a SHARED legacy table core was
 * forbidden to write. The notice on it said an account is "created on the storefront or in the old
 * dashboard" — and on the standalone eleganceeg.com deployment neither is reachable, so that
 * sentence described a workflow with nowhere to happen. An administrator could not onboard anybody
 * at all, which was one of the two things keeping the legacy system alive (live review §3.2).
 *
 * AGENTS §2.18 now permits two operations on that table, and this screen uses one of them through
 * {@see DashboardAccounts} — the single door, which refuses every other write at the model. What
 * has NOT changed: this screen never edits an account, never deletes one, and never sets somebody
 * else's password. Removing a person's access is a revoked GRANT, which is a `core_user_roles` row.
 *
 * The role is granted in the SAME operation as the account, inside one transaction. An account
 * created without a grant is a person who can sign in and is then refused with a 403 — a worse
 * half-state than not having created them.
 *
 * ── The artisan command stays ────────────────────────────────────────────────────────────────
 *
 * `php artisan manage:role` is the BOOTSTRAP path and does not retire: the first grant on a fresh
 * database cannot be made from a screen that requires a grant to open. Rehearsal #3 needed exactly
 * that after the database was rebuilt (the dashboard tables were wiped with it), and the command
 * is what got the team back in.
 *
 * ── Admin only, and self-demotion is refused ─────────────────────────────────────────────────
 *
 * `manage-users` is not a data-entry ability. And an administrator may not revoke their OWN admin
 * grant: the screen would lock the last key inside the room. Removing an administrator is another
 * administrator's job, or the command's.
 */
final class UserRoleController
{
    public function __construct(private readonly Roles $roles) {}

    /**
     * Everyone who holds a dashboard grant, plus the accounts a search finds — because granting
     * requires finding an existing account first, and the team knows people by e-mail.
     */
    public function index(Request $request): Response|StreamedResponse
    {
        $term = trim(Coerce::str($request->input('q')));
        $actorScope = $this->actorScope($request);
        $grants = self::grantRows($actorScope);

        /*
         * WHO CAN GET INTO THE DASHBOARD, as a file — the list somebody reviews quarterly.
         *
         * It exports the GRANTS and never the account search. That search reaches into `users`,
         * which holds real customers, and a screen that deliberately refuses to page through them
         * must not hand them over in a download either. No password, no remember-token, no hash
         * appears here — not because they are filtered out, but because `grantRows()` never
         * selected one.
         */
        $export = TableExport::wanted($request, 'dashboard-access', [
            // The screen's own headings, off the screen's own keys: the file and the page are the
            // same list, so a second English "Permission" here would be one waiting to disagree.
            'email' => ManageText::t('common.email', 'البريد'),
            'name' => ManageText::t('common.name', 'الاسم'),
            'role' => ManageText::t('users.role', 'الصلاحية'),
            'storefront' => [ManageText::t('users.scope', 'النطاق'), fn (array $row): string => Coerce::nstr($row['storefront'] ?? null)
                ?? ManageText::t('common.all_storefronts', 'كل المتاجر')],
            'granted_by' => ManageText::t('users.granted_by', 'منحها'),
            'created_at' => ManageText::t('common.date', 'التاريخ'),
            'legacy_type' => ManageText::t('users.legacy_type', 'خانة قديمة'),
        ], $grants);
        if ($export !== null) {
            return $export;
        }

        return Inertia::render('Manage/Users/Index', [
            'grants' => $grants,
            // The search is deliberately narrow and never lists the whole `users` table: it holds
            // customers, and a dashboard screen has no business paging through them.
            'search' => [
                'term' => $term,
                'results' => $term === '' ? [] : self::search($term),
                'searched' => $term !== '',
            ],
            /*
             * ── DATA-ENTRY FIRST (J-2, 2026-09-19) ─────────────────────────────────────────
             *
             * The select defaulted to its first option, and its first option was administrator —
             * so granting a new data-entry hire in a hurry, without touching this field, granted
             * full access: payments, storefront settings, users, order cancellation. Handed out by
             * omission, in the one form on the dashboard where omission is most expensive.
             *
             * The order is the fix. The least-privileged role is the default because a wrong
             * default should fail in the direction of "they will come and ask for more", never
             * "nobody finds out until something is deleted". The list is short enough that
             * choosing the other one costs one click.
             */
            'roles' => [
                ['value' => Role::DataEntry->value, 'label' => ManageText::t('users.role_data_entry', 'إدخال بيانات')],
                ['value' => Role::Admin->value, 'label' => ManageText::t('users.role_admin_full', 'مدير (كل الصلاحيات)')],
            ],
            // Only what this actor may grant: a scoped admin is never offered "all storefronts"
            // or a storefront outside its own grant (security audit, Finding 1).
            'storefronts' => self::storefrontOptions($actorScope),
            'current_user_id' => Coerce::int($request->user()?->getAuthIdentifier()),
        ]);
    }

    /**
     * Grant a role to an EXISTING account, optionally scoped to one storefront.
     *
     * ⚠ BEFORE ISSUING THE FIRST SCOPED GRANT, READ THIS (2026-09-23). The business does not use
     * scoped grants — one team runs both storefronts; every operator is an unscoped admin or
     * data-entry. The system supports them, and the grant screen, the Gate and the storefront
     * settings are scope-safe (security audit, Finding 1). `PromotionController` is NOT: it takes
     * `storefront_id` from the body with no scope check, so a scoped operator could put a discount
     * on a shop they were never given. That gap is left open deliberately and is harmless until a
     * `storefront_id` other than null is granted here. Close it first — see its class docblock.
     *
     * The e-mail must already exist: `Rule::exists` is the whole enforcement of "grants only", and
     * the message says where an account comes from instead of offering to create one.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'email' => ['required', 'string', 'email', 'max:191', Rule::exists('users', 'email')],
            'role' => ['required', 'string', Rule::in([Role::Admin->value, Role::DataEntry->value])],
            'storefront_id' => ['nullable', 'integer', Rule::exists('storefronts', 'id')],
        ], [
            'email.exists' => ManageText::t('users.account_not_found_hint', 'لا يوجد حساب بهذا البريد. أنشئه من «إضافة موظّف» بالأعلى.'),
        ]));

        $user = User::query()->where('email', Coerce::str($data['email']))->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => ManageText::t('users.account_not_found', 'لا يوجد حساب بهذا البريد.'),
            ]);
        }

        $role = Role::from(Coerce::str($data['role']));
        $storefrontId = Coerce::nint($data['storefront_id'] ?? null);
        $this->assertWithinActorScope($request, $storefrontId);

        $this->roles->assign($user, $role, $storefrontId, $request->user());

        return back()->with('status', $storefrontId === null
            ? ManageText::t('users.granted_all_storefronts', 'تم منح الصلاحية على كل المتاجر.')
            : ManageText::t('users.granted_one_storefront', 'تم منح الصلاحية على متجر واحد.'));
    }

    /**
     * Create a dashboard account AND grant its role, in one operation.
     *
     * ── The screen that ended the old dashboard (2026-09-20) ────────────────────────
     *
     * Until today this screen granted roles to accounts that had to already exist, and told the
     * operator they were "created on the storefront or in the old dashboard". On the standalone
     * deployment the old dashboard is not reachable and the storefront is not on this host, so that
     * sentence described a workflow with nowhere to happen: an administrator could not onboard
     * anybody. It was one of the two things keeping the legacy system alive (live review §3.2).
     *
     * The write goes through {@see DashboardAccounts}, the single permitted door, which also
     * decides what a new row may contain — notably `type = 'User'`, never a legacy admin value.
     * Access comes from the role granted alongside it, not from that column.
     *
     * Both halves are one transaction inside that class: an account created without a role is a
     * person who can sign in and is then refused at the door with a 403, which is a worse state
     * than not having created them.
     */
    public function storeAccount(Request $request, DashboardAccounts $accounts): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:'.DashboardAccounts::MIN_PASSWORD, 'confirmed'],
            'role' => ['required', 'string', Rule::in([Role::Admin->value, Role::DataEntry->value])],
            'storefront_id' => ['nullable', 'integer', Rule::exists('storefronts', 'id')],
        ], [
            'email.unique' => ManageText::t('users.email_taken', 'هذا البريد له حساب بالفعل.'),
        ]));

        // BEFORE the account is created: a refused grant must not leave a role-less account behind.
        $this->assertWithinActorScope($request, Coerce::nint($data['storefront_id'] ?? null));

        $accounts->create(
            [
                'first_name' => Coerce::str($data['first_name']),
                'last_name' => Coerce::str($data['last_name']),
                'email' => Coerce::str($data['email']),
                'password' => Coerce::str($data['password']),
            ],
            Role::from(Coerce::str($data['role'])),
            Coerce::nint($data['storefront_id'] ?? null),
            $request->user(),
        );

        return back()->with('status', ManageText::t(
            'users.account_created',
            'تم إنشاء الحساب ومنح الصلاحية. سلّم كلمة المرور للموظف واطلب منه تغييرها من صفحة حسابه.',
        ));
    }

    /**
     * Revoke one grant row.
     *
     * Two refusals, both about locking yourself out: an administrator may not revoke their own
     * admin grant, and the LAST unscoped admin grant in the system may not be revoked at all. The
     * second is the one that matters on a bad day — a dashboard nobody can administer is recovered
     * only from the command line.
     */
    public function destroy(Request $request, int $grant): RedirectResponse
    {
        $row = DB::table('core_user_roles')->where('id', $grant)->first(['id', 'user_id', 'role', 'storefront_id']);
        abort_if(! is_object($row), 404);

        $grantRow = Row::cast($row);
        $userId = Row::int($grantRow, 'user_id');
        $role = Row::str($grantRow, 'role');
        $storefrontId = Row::nint($grantRow, 'storefront_id');
        $currentUserId = Coerce::int($request->user()?->getAuthIdentifier());

        // A scoped admin may revoke only grants inside its own storefronts — which also means it
        // can never touch an UNSCOPED grant, the last global admin's included (Finding 1).
        $this->assertWithinActorScope($request, $storefrontId);

        if ($role === Role::Admin->value && $userId === $currentUserId) {
            throw ValidationException::withMessages([
                'grant' => ManageText::t('users.revoke_self_refused', 'لا يمكنك سحب صلاحية المدير من نفسك. اطلب من مدير آخر أن يفعلها.'),
            ]);
        }

        if ($role === Role::Admin->value && $storefrontId === null && self::unscopedAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'grant' => ManageText::t('users.revoke_last_admin_refused', 'هذه آخر صلاحية مدير عامة في النظام: سحبها يترك اللوحة بلا مدير. امنح مديرًا آخر أولًا.'),
            ]);
        }

        $user = User::query()->whereKey($userId)->first();
        if ($user !== null) {
            $this->roles->revoke($user, Role::from($role), $storefrontId);
        } else {
            // The account vanished from the shared table (the legacy app deleted it). The GRANT is
            // ours to clean up, and leaving an orphan row would keep an ability alive for an id
            // that could be reissued.
            DB::table('core_user_roles')->where('id', $grant)->delete();
        }

        return back()->with('status', ManageText::t('users.revoked', 'تم سحب الصلاحية.'));
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────

    /**
     * Every grant, with the account it belongs to — or, for a scoped actor, every grant inside its
     * scope.
     *
     * @param  list<int>|null  $actorScope
     * @return list<array<string, mixed>>
     */
    private static function grantRows(?array $actorScope = null): array
    {
        $out = [];
        foreach (
            DB::table('core_user_roles as r')
                // A scoped actor sees only the grants it could act on. Unscoped grants, and those
                // of other storefronts, are not its business (Finding 1).
                ->when($actorScope !== null, fn (Builder $q) => $q->whereIn('r.storefront_id', $actorScope === [] ? [0] : $actorScope))
                ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->leftJoin('users as g', 'g.id', '=', 'r.granted_by')
                ->leftJoin('storefronts as s', 's.id', '=', 'r.storefront_id')
                ->orderBy('u.email')->orderBy('r.role')->orderBy('r.storefront_id')
                // `users` carries first_name/last_name and NO `name` column — measured on the
                // production schema, not assumed. The display name is assembled below so the
                // screen shows one field.
                ->get([
                    'r.id', 'r.user_id', 'r.role', 'r.storefront_id', 'r.created_at',
                    'u.email', 'u.first_name', 'u.last_name', 'u.type as legacy_type',
                    'g.email as granted_by_email', 's.name as storefront_name',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $out[] = [
                'id' => Row::int($row, 'id'),
                'user_id' => Row::int($row, 'user_id'),
                'email' => Row::nstr($row, 'email'),
                'name' => self::displayName($row),
                // The LEGACY admin flag, shown for contrast: it means nothing here (core reads
                // grants, not `users.type`), and seeing both stops "but they are SuperAdmin".
                'legacy_type' => Row::nstr($row, 'legacy_type'),
                'role' => Row::str($row, 'role'),
                'storefront_id' => Row::nint($row, 'storefront_id'),
                'storefront' => Row::nstr($row, 'storefront_name'),
                'granted_by' => Row::nstr($row, 'granted_by_email'),
                'created_at' => Row::nstr($row, 'created_at'),
            ];
        }

        return $out;
    }

    /**
     * Accounts matching a search, with whether they already hold a grant.
     *
     * @return list<array<string, mixed>>
     */
    private static function search(string $term): array
    {
        $out = [];
        foreach (
            DB::table('users')
                ->where(function (Builder $query) use ($term): void {
                    $query->where('email', 'like', '%'.$term.'%')
                        ->orWhere('first_name', 'like', '%'.$term.'%')
                        ->orWhere('last_name', 'like', '%'.$term.'%');
                })
                ->orderBy('email')->limit(20)
                ->get(['id', 'email', 'first_name', 'last_name', 'type']) as $raw
        ) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $out[] = [
                'id' => $id,
                'email' => Row::nstr($row, 'email'),
                'name' => self::displayName($row),
                'legacy_type' => Row::nstr($row, 'type'),
                'has_grant' => DB::table('core_user_roles')->where('user_id', $id)->exists(),
            ];
        }

        return $out;
    }

    /** First and last name as one string, or null when the account carries neither. */
    private static function displayName(\stdClass $row): ?string
    {
        $name = trim((Row::nstr($row, 'first_name') ?? '').' '.(Row::nstr($row, 'last_name') ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * The storefronts the ACTOR may grant or revoke on: null = all of them.
     *
     * ── Security audit, Finding 1 (2026-09-23) ───────────────────────────────────────────────
     *
     * These three writes checked the ROLE being granted and never the actor's own reach. A
     * Brand-Fashion-scoped admin passes `can:manage-users` (the route asks the question without a
     * storefront), and could then POST `{role: admin, storefront_id: null}` — minting itself an
     * UNSCOPED admin — or revoke any grant by id, every other operator's included. The Gate fix
     * stops the short-circuit; this is the check that stops the write.
     *
     * Read per ABILITY (`manage-users`), not per user: an unscoped grant that cannot manage users
     * must not widen where this actor may grant.
     *
     * @return list<int>|null
     */
    private function actorScope(Request $request): ?array
    {
        $actor = $request->user();
        abort_if(! $actor instanceof User, 403);

        return $this->roles->scopeForAbility($actor, Role::MANAGE_USERS);
    }

    /**
     * Refuse a grant or revocation outside the actor's scope — 403, not a form error: the form
     * never offers such a target, so reaching here takes a hand-built request.
     */
    private function assertWithinActorScope(Request $request, ?int $targetStorefrontId): void
    {
        $scope = $this->actorScope($request);
        if ($scope === null) {
            return;                                          // an unscoped admin: unaffected
        }
        abort_if($targetStorefrontId === null || ! in_array($targetStorefrontId, $scope, true), 403);
    }

    private static function unscopedAdminCount(): int
    {
        return (int) DB::table('core_user_roles')
            ->where('role', Role::Admin->value)->whereNull('storefront_id')->count();
    }

    /**
     * @param  list<int>|null  $actorScope
     * @return list<array{value: string, label: string}>
     */
    private static function storefrontOptions(?array $actorScope = null): array
    {
        $out = $actorScope === null
            ? [['value' => '', 'label' => ManageText::t('common.all_storefronts', 'كل المتاجر')]]
            : [];
        $query = DB::table('storefronts')->orderBy('id');
        if ($actorScope !== null) {
            $query->whereIn('id', $actorScope === [] ? [0] : $actorScope);
        }
        foreach ($query->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $out[] = ['value' => (string) Row::int($row, 'id'), 'label' => Row::nstr($row, 'name') ?? ''];
        }

        return $out;
    }
}
