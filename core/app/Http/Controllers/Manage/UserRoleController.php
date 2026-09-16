<?php

namespace App\Http\Controllers\Manage;

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
 * ── GRANTS ONLY. This screen never touches the `users` table ─────────────────────────────────
 *
 * `users` is a SHARED legacy table and AGENTS §3 is explicit about it: core does not create,
 * rename, disable or password-reset an account, and four framework defaults that would have
 * written it on our behalf are off. So this screen does exactly one thing — it writes
 * `core_user_roles`, which is core's own table: a row saying "this existing account may do this
 * here". There is no "add user" button, and the absence is the feature.
 *
 * An account is created where accounts are created: the storefront's own registration, or the
 * legacy dashboard. This screen finds it and grants it access.
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
        $grants = self::grantRows();

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
            'legacy_type' => ManageText::t('users.legacy_type', 'النوع في النظام القديم'),
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
            'roles' => [
                ['value' => Role::Admin->value, 'label' => ManageText::t('users.role_admin_full', 'مدير (كل الصلاحيات)')],
                ['value' => Role::DataEntry->value, 'label' => ManageText::t('users.role_data_entry', 'إدخال بيانات')],
            ],
            'storefronts' => self::storefrontOptions(),
            'current_user_id' => Coerce::int($request->user()?->getAuthIdentifier()),
        ]);
    }

    /**
     * Grant a role to an EXISTING account, optionally scoped to one storefront.
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
            'email.exists' => ManageText::t('users.account_not_found_hint', 'لا يوجد حساب بهذا البريد. الحسابات تُنشأ من المتجر أو من الداشبورد القديم — هذه الشاشة تمنح الصلاحيات فقط.'),
        ]));

        $user = User::query()->where('email', Coerce::str($data['email']))->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => ManageText::t('users.account_not_found', 'لا يوجد حساب بهذا البريد.'),
            ]);
        }

        $role = Role::from(Coerce::str($data['role']));
        $storefrontId = Coerce::nint($data['storefront_id'] ?? null);

        $this->roles->assign($user, $role, $storefrontId, $request->user());

        return back()->with('status', $storefrontId === null
            ? ManageText::t('users.granted_all_storefronts', 'تم منح الصلاحية على كل المتاجر.')
            : ManageText::t('users.granted_one_storefront', 'تم منح الصلاحية على متجر واحد.'));
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

        if ($role === Role::Admin->value && $userId === $currentUserId) {
            throw ValidationException::withMessages([
                'grant' => ManageText::t('users.revoke_self_refused', 'لا يمكنك سحب صلاحية المدير من نفسك. اطلب من مدير آخر أن يفعلها، أو استخدم `php artisan manage:role revoke`.'),
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
     * Every grant, with the account it belongs to.
     *
     * @return list<array<string, mixed>>
     */
    private static function grantRows(): array
    {
        $out = [];
        foreach (
            DB::table('core_user_roles as r')
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

    private static function unscopedAdminCount(): int
    {
        return (int) DB::table('core_user_roles')
            ->where('role', Role::Admin->value)->whereNull('storefront_id')->count();
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [['value' => '', 'label' => ManageText::t('common.all_storefronts', 'كل المتاجر')]];
        foreach (DB::table('storefronts')->orderBy('id')->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $out[] = ['value' => (string) Row::int($row, 'id'), 'label' => Row::nstr($row, 'name') ?? ''];
        }

        return $out;
    }
}
