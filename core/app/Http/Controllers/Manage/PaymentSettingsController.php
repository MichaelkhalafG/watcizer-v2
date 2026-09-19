<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Roles;
use App\Domain\Activity\ActivityLog;
use App\Domain\Payment\MethodList;
use App\Domain\Payment\ProviderRegistry;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentMethod;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payments — providers, methods and the merged ordering, per storefront (study §3.9.7).
 *
 * ── The one rule this screen exists to keep ──────────────────────────────────────────────────
 *
 * **No stored credential is ever rendered back.** Not in a prop, not in a hidden input, not in a
 * "reveal" affordance, not in a validation error that echoes the old value. The fields render
 * EMPTY with "leave blank to keep the current value", and a blank field does not overwrite. What
 * the screen may say about a secret is whether it is SET and when it changed — never a character
 * of it (AGENTS §3, §3.9.7). `PaymentSecrecyTest` asserts the stored value appears nowhere in the
 * rendered page, and `StorefrontPaymentProvider` hides the attribute so `toArray()` — which is
 * what an Inertia prop IS — cannot carry it by accident.
 *
 * `integration_id` is NOT a credential: Paymob puts it in the signed callback payload, and the
 * admin needs it to match a row in the merchant portal. It is shown normally.
 *
 * ── Scoped to ONE storefront, always ─────────────────────────────────────────────────────────
 *
 * Never a global cross-storefront list. The URL names the storefront, `EnsureStorefrontScope`
 * answers 404 for a storefront this grant does not cover, and every query below filters by it —
 * because "which account takes this money" is a per-storefront question and a screen that mixed
 * two storefronts' contracts would be one keystroke from sending Brand Fashion's money to
 * Watchizer's account.
 */
final class PaymentSettingsController
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function index(Request $request, Storefront $storefront): Response
    {
        $storefrontId = (int) $storefront->id;

        return Inertia::render('Manage/Payments/Index', [
            'storefront' => ['id' => $storefrontId, 'code' => (string) $storefront->code, 'name' => (string) $storefront->name],
            /*
             * The storefronts this operator may switch to (item 15, 2026-09-18).
             *
             * Every other per-storefront screen — products, categories, placement, banners — offers
             * this. Payments did not, so the only way onto Brand Fashion's payment settings was to
             * type its id into the address bar: the sidebar links to whichever storefront you were
             * last looking at, and there was nothing on the screen to say another existed. The
             * developer's report was "the payment methods screen only shows Watchizer", and that is
             * exactly what it did.
             *
             * SCOPED, unlike the catalogue screens' switcher. Payments is behind a per-storefront
             * grant and answers 404 for a storefront outside it (§3.11.14 — out of scope is 404, not
             * 403, so an id cannot be confirmed by probing). A switcher that listed every storefront
             * would hand a scoped operator a link that 404s, which reads as a broken dashboard
             * rather than as a permission they do not have.
             */
            'storefronts' => self::switchableStorefronts(),
            'providers' => $this->providerRows($storefrontId),
            // The MERGED list: every candidate row across every provider, in the storefront's own
            // order, each saying whether it currently SERVES its method key and who took it if
            // not. "Which provider is taking this money" must never be inferred from a sort order.
            'merged' => MethodList::forAdmin($storefrontId, 'ar'),
            'customer_preview' => MethodList::forCustomer($storefrontId, 'ar'),
            'registry' => array_map(
                fn (string $key): array => [
                    'value' => $key,
                    'label' => $key,
                    'credential_fields' => $this->registry->credentialFields($key),
                ],
                $this->registry->keys(),
            ),
            'method_keys' => self::METHOD_KEYS,
        ]);
    }

    /** Add a contract. The provider constant must be one the registry actually implements. */
    public function storeProvider(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'provider' => ['required', 'string', 'max:32', Rule::in($this->registry->keys())],
            'is_enabled' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array'],
        ]));

        $provider = Coerce::str($data['provider']);

        $exists = StorefrontPaymentProvider::query()
            ->where('storefront_id', $storefront->id)->where('provider', $provider)->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'provider' => ManageText::t('payments.contract_exists', 'هذا المتجر يملك عقدًا مع هذا المزوّد بالفعل. عدّله بدلًا من إضافة ثانٍ — العقد واحد لكل مزوّد لكل متجر.'),
            ]);
        }

        StorefrontPaymentProvider::query()->create([
            'storefront_id' => (int) $storefront->id,
            'provider' => $provider,
            'is_enabled' => (bool) $data['is_enabled'],
            'credentials' => self::mergeCredentials(null, $request, $provider, $this->registry),
            'settings' => null,
        ]);

        return back()->with('status', ManageText::t('payments.contract_added', 'تمت إضافة العقد.'));
    }

    /**
     * Edit a contract: the enable switch, and credentials where a field was filled in.
     *
     * A BLANK credential field keeps the stored value. That is what makes the write-only field
     * usable: an admin editing the enable switch must not have to retype three secrets, and a
     * blank input that cleared the key would break payments on a save nobody thought was risky.
     */
    public function updateProvider(Request $request, Storefront $storefront, int $provider): RedirectResponse
    {
        $row = self::requireProvider($storefront, $provider);

        $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array'],
        ]);

        $key = Coerce::str($row->getAttribute('provider'));
        $wasEnabled = (bool) $row->getAttribute('is_enabled');
        $oldCredentials = $row->getAttribute('credentials');

        $row->update([
            'is_enabled' => (bool) $request->input('is_enabled'),
            'credentials' => self::mergeCredentials($row, $request, $key, $this->registry),
        ]);

        /*
         * MONEY, so it is logged — and the credential VALUES never enter the log.
         *
         * `ActivityLog::REDACTED_FIELDS` matches `credentials` and replaces both sides with a
         * marker. The row still records THAT the Paymob secret changed and who changed it, which is
         * the whole audit question; copying it into a second, less-guarded table would undo the
         * encryption it sits behind in the first place. A hash of the value is passed instead of the
         * value, so "did it actually change?" stays answerable without the secret being present.
         */
        ActivityLog::record(
            'storefront_payment_providers',
            $provider,
            ActivityLog::UPDATED,
            ['is_enabled' => $wasEnabled, 'credentials' => self::credentialPrint($oldCredentials)],
            [
                'is_enabled' => (bool) $request->input('is_enabled'),
                'credentials' => self::credentialPrint($row->getAttribute('credentials')),
            ],
            label: $key,
            storefrontId: Coerce::nint($storefront->getKey()),
        );

        return back()->with('status', ManageText::t('payments.contract_saved', 'تم حفظ العقد.'));
    }

    /**
     * Delete a contract — and its methods with it (CASCADE).
     *
     * The confirmation on the screen names the storefront and says how many methods go, because
     * deleting a contract silently takes the customer's buttons with it (§3.9.7).
     */
    public function destroyProvider(Request $request, Storefront $storefront, int $provider): RedirectResponse
    {
        $row = self::requireProvider($storefront, $provider);
        $methods = (int) $row->methods()->count();
        $key = Coerce::str($row->getAttribute('provider'));
        $row->delete();

        ActivityLog::record(
            'storefront_payment_providers', $provider, ActivityLog::DELETED,
            before: ['provider' => $key, 'methods_removed' => $methods],
            label: $key,
            storefrontId: Coerce::nint($storefront->getKey()),
        );

        return back()->with('status', $methods === 0
            ? ManageText::t('payments.contract_deleted', 'تم حذف العقد.')
            : ManageText::t('payments.contract_deleted_with_methods', 'تم حذف العقد و:count طريقة دفع تابعة له.', ['count' => $methods]));
    }

    /** Add a method under a contract of THIS storefront. */
    public function storeMethod(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate(self::methodRules($storefront) + [
            'storefront_payment_provider_id' => ['required', 'integer'],
        ]));

        $providerRow = self::requireProvider($storefront, Coerce::int($data['storefront_payment_provider_id']));
        $method = Coerce::str($data['method']);

        $duplicate = StorefrontPaymentMethod::query()
            ->where('storefront_payment_provider_id', $providerRow->getAttribute('id'))
            ->where('method', $method)->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'method' => ManageText::t('payments.method_exists', 'هذه الطريقة موجودة بالفعل تحت هذا العقد. (نفس الطريقة تحت عقد آخر مسموحة — العميل يرى واحدة فقط.)'),
            ]);
        }

        $row = StorefrontPaymentMethod::query()->create([
            'storefront_payment_provider_id' => Coerce::int($providerRow->getAttribute('id')),
            'method' => $method,
            'integration_id' => Coerce::nstr($data['integration_id'] ?? null),
            'icon' => Coerce::nstr($data['icon'] ?? null),
            'is_enabled' => (bool) $data['is_enabled'],
            'sort' => Coerce::int($data['sort'] ?? 0),
            'settings' => null,
        ]);

        self::writeLabels($row, $request);

        ActivityLog::record(
            'storefront_payment_methods',
            Coerce::nint($row->getKey()),
            ActivityLog::CREATED,
            after: ['method' => Coerce::str($data['method']), 'is_enabled' => (bool) $data['is_enabled']],
            label: Coerce::str($data['method']),
            storefrontId: Coerce::nint($storefront->getKey()),
        );

        return back()->with('status', ManageText::t('payments.method_added', 'تمت إضافة طريقة الدفع.'));
    }

    public function updateMethod(Request $request, Storefront $storefront, int $method): RedirectResponse
    {
        $row = self::requireMethod($storefront, $method);
        $data = Coerce::arr($request->validate(self::methodRules($storefront)));

        /*
         * A payment METHOD is what the customer sees and picks, so who enabled or disabled one is
         * a money question in the same way the contract above is. No credential lives on this row
         * — `integration_id` is an identifier, not a secret (study §3.9.1) — so the values are
         * logged in full.
         */
        $before = ActivityLog::fields($row->only(['method', 'integration_id', 'icon', 'is_enabled', 'sort']));

        $row->update([
            'method' => Coerce::str($data['method']),
            'integration_id' => Coerce::nstr($data['integration_id'] ?? null),
            'icon' => Coerce::nstr($data['icon'] ?? null),
            'is_enabled' => (bool) $data['is_enabled'],
            'sort' => Coerce::int($data['sort'] ?? 0),
        ]);

        self::writeLabels($row, $request);

        ActivityLog::record(
            'storefront_payment_methods', $method, ActivityLog::UPDATED,
            $before,
            ActivityLog::fields($row->fresh()?->only(['method', 'integration_id', 'icon', 'is_enabled', 'sort'])),
            label: Coerce::str($data['method']),
            storefrontId: Coerce::nint($storefront->getKey()),
        );

        return back()->with('status', ManageText::t('payments.method_saved', 'تم حفظ طريقة الدفع.'));
    }

    public function destroyMethod(Request $request, Storefront $storefront, int $method): RedirectResponse
    {
        // Named BEFORE the delete: afterwards there is nothing left to name it by, and "method 7
        // was deleted" is not an answer anybody can act on.
        $row = self::requireMethod($storefront, $method);
        $name = Coerce::str($row->getAttribute('method'));
        $row->delete();

        ActivityLog::record(
            'storefront_payment_methods', $method, ActivityLog::DELETED,
            before: ['method' => $name],
            label: $name,
            storefrontId: Coerce::nint($storefront->getKey()),
        );

        return back()->with('status', ManageText::t('payments.method_deleted', 'تم حذف طريقة الدفع.'));
    }

    /**
     * The merged ordering: one drag-and-drop list across every provider (§3.9.7).
     *
     * `sort` is storefront-wide because the merchant thinks "card first, then valU, then cash" —
     * an ordering of METHODS that happens to cross contracts. Ordering inside each provider
     * separately cannot express that intention, and the order also decides which contract WINS a
     * duplicated method key (lowest sort), so this screen is where the money is routed.
     */
    public function reorder(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]));

        /** @var list<int> $ids */
        $ids = array_values(array_map(fn (mixed $id): int => Coerce::int($id), Coerce::arr($data['ids'])));

        // Every id must be a method of THIS storefront: a crafted payload naming another
        // storefront's method would otherwise reorder — and re-route — its payments.
        $owned = DB::table('storefront_payment_methods as m')
            ->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
            ->where('p.storefront_id', $storefront->id)
            ->whereIn('m.id', $ids)
            ->pluck('m.id')->map(fn (mixed $v): int => Coerce::int($v))->all();

        $foreign = array_values(array_diff($ids, $owned));
        if ($foreign !== []) {
            throw ValidationException::withMessages([
                'ids' => ManageText::t('payments.method_not_of_storefront', 'القائمة تحتوي طريقة دفع لا تتبع هذا المتجر.'),
            ]);
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                DB::table('storefront_payment_methods')->where('id', $id)
                    ->update(['sort' => $position, 'updated_at' => now()]);
            }
        });

        return back()->with('status', ManageText::t('payments.order_saved', 'تم حفظ الترتيب. الطريقة الأعلى في القائمة هي التي تستقبل الأموال عند التكرار.'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * The method keys the screen offers. Application constants, never an enum in the database
     * (§2.1-6) — and valU and Tamara appear here as METHODS, which is the whole point.
     *
     * @var list<string>
     */
    private const METHOD_KEYS = ['card', 'valu', 'tamara', 'wallet', 'fawry_code', 'cod', 'whatsapp'];

    /** @return array<string, list<mixed>> */
    private static function methodRules(Storefront $storefront): array
    {
        return [
            'method' => ['required', 'string', 'max:32', Rule::in(self::METHOD_KEYS)],
            // NOT a credential: shown normally, and the provider needs it to route the customer.
            'integration_id' => ['nullable', 'string', 'max:64'],
            'icon' => ['nullable', 'string', 'max:64'],
            'is_enabled' => ['required', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'label' => ['required', 'array'],
            'label.ar' => ['required', 'string', 'max:191'],
            'label.en' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * Merge submitted credentials over the stored ones: a filled field replaces, a BLANK field
     * keeps, and a key the provider does not declare is dropped rather than stored.
     *
     * Returns null when the result is empty, so a `cod` contract stores NULL rather than `{}` and
     * `credentialsSet()` can answer honestly.
     *
     * @return array<string, string>|null
     */
    private static function mergeCredentials(
        ?StorefrontPaymentProvider $row,
        Request $request,
        string $providerKey,
        ProviderRegistry $registry,
    ): ?array {
        /** @var array<string, mixed> $submitted */
        $submitted = Coerce::arr($request->input('credentials'));
        $stored = $row === null ? [] : $row->getAttribute('credentials');
        $stored = is_array($stored) ? $stored : [];

        $out = [];
        foreach ($registry->credentialFields($providerKey) as $field) {
            $incoming = $submitted[$field] ?? null;
            $incoming = is_string($incoming) ? trim($incoming) : '';

            if ($incoming !== '') {
                $out[$field] = $incoming;               // replaced
            } else {
                $keep = $stored[$field] ?? null;
                if (is_string($keep) && $keep !== '') {
                    $out[$field] = $keep;               // blank means KEEP
                }
            }
        }

        return $out === [] ? null : $out;
    }

    private static function writeLabels(StorefrontPaymentMethod $row, Request $request): void
    {
        /** @var array<string, mixed> $labels */
        $labels = Coerce::arr($request->input('label'));
        foreach (['ar', 'en'] as $locale) {
            $label = $labels[$locale] ?? null;
            $label = is_string($label) ? trim($label) : '';
            if ($label === '') {
                // An emptied EN label is deleted rather than stored blank — the same rule the
                // lookup screens follow, so a missing translation reads as missing everywhere.
                DB::table('storefront_payment_method_translations')
                    ->where('storefront_payment_method_id', $row->getAttribute('id'))
                    ->where('locale', $locale)->delete();

                continue;
            }
            DB::table('storefront_payment_method_translations')->updateOrInsert(
                ['storefront_payment_method_id' => Coerce::int($row->getAttribute('id')), 'locale' => $locale],
                ['label' => $label],
            );
        }
    }

    /**
     * A contract of THIS storefront, or 404 — never 403, which would confirm it exists somewhere
     * else (§3.11.14).
     */
    private static function requireProvider(Storefront $storefront, int $providerId): StorefrontPaymentProvider
    {
        $row = StorefrontPaymentProvider::query()
            ->where('id', $providerId)->where('storefront_id', $storefront->id)->first();

        abort_if($row === null, 404);

        return $row;
    }

    private static function requireMethod(Storefront $storefront, int $methodId): StorefrontPaymentMethod
    {
        $row = StorefrontPaymentMethod::query()
            ->whereKey($methodId)
            ->whereHas('provider', fn ($query) => $query->where('storefront_id', $storefront->id))
            ->first();

        abort_if($row === null, 404);

        return $row;
    }

    /**
     * The ACTIVE storefronts this operator's grant reaches, for the switcher (item 15).
     *
     * @return list<array{value: string, label: string}>
     */
    private static function switchableStorefronts(): array
    {
        $user = auth()->user();
        $scope = $user instanceof User ? app(Roles::class)->storefrontScope($user) : [];

        $query = DB::table('storefronts')->where('is_active', true)->orderBy('id');
        // An EMPTY scope means unscoped — every storefront — which is what an administrator holds.
        // A non-empty one is the exact list, so the switcher and the route agree by construction.
        if (is_array($scope) && $scope !== []) {
            $query->whereIn('id', $scope);
        }

        $out = [];
        foreach ($query->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $out[] = ['value' => (string) $id, 'label' => Row::nstr($row, 'name') ?? ('#'.$id)];
        }

        return $out;
    }

    /**
     * The contracts, with what may be said about their credentials: whether they are set, WHICH
     * keys are present, and when they last changed. Never a value.
     *
     * @return list<array<string, mixed>>
     */
    private function providerRows(int $storefrontId): array
    {
        $out = [];
        foreach (
            StorefrontPaymentProvider::query()->where('storefront_id', $storefrontId)
                ->orderBy('provider')->get() as $row
        ) {
            $key = Coerce::str($row->getAttribute('provider'));
            $fields = $this->registry->credentialFields($key);
            $present = $row->credentialKeys();

            $out[] = [
                'id' => Coerce::int($row->getAttribute('id')),
                'provider' => $key,
                'is_enabled' => (bool) $row->getAttribute('is_enabled'),
                'implemented' => $this->registry->has($key),
                // Booleans and NAMES only — the three things a rotation needs to be checkable.
                'credentials_set' => $row->credentialsSet(),
                'credential_fields' => $fields,
                'credential_keys_present' => $present,
                'credentials_complete' => $fields === [] || array_values(array_intersect($fields, $present)) === $fields,
                'needs_credentials' => $fields !== [],
                'methods' => self::methodRows(Coerce::int($row->getAttribute('id'))),
                'updated_at' => Coerce::nstr($row->getAttribute('updated_at')),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function methodRows(int $providerId): array
    {
        $out = [];
        foreach (
            DB::table('storefront_payment_methods as m')
                ->where('m.storefront_payment_provider_id', $providerId)
                ->orderBy('m.sort')->orderBy('m.id')
                ->get(['m.id', 'm.method', 'm.integration_id', 'm.icon', 'm.is_enabled', 'm.sort']) as $raw
        ) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $labels = [];
            foreach (
                DB::table('storefront_payment_method_translations')
                    ->where('storefront_payment_method_id', $id)->get(['locale', 'label']) as $rawLabel
            ) {
                $label = Row::cast($rawLabel);
                $labels[Row::str($label, 'locale')] = Row::str($label, 'label');
            }

            $out[] = [
                'id' => $id,
                'method' => Row::str($row, 'method'),
                'integration_id' => Row::nstr($row, 'integration_id'),
                'icon' => Row::nstr($row, 'icon'),
                'is_enabled' => Row::bool($row, 'is_enabled'),
                'sort' => Row::int($row, 'sort'),
                'label' => ['ar' => $labels['ar'] ?? '', 'en' => $labels['en'] ?? ''],
            ];
        }

        return $out;
    }

    /**
     * A credential set as a FINGERPRINT, never as a value.
     *
     * Answers "did the credentials change?" without putting them anywhere. A SHA-256 prefix of the
     * canonical JSON: two identical sets print the same, two different sets print differently, and
     * nothing about the secret can be recovered from it. `ActivityLog` redacts the field as well —
     * this is the belt behind that brace, so a future caller that forgets the field name still
     * cannot leak a key through this path.
     */
    private static function credentialPrint(mixed $credentials): string
    {
        if ($credentials === null || $credentials === [] || $credentials === '') {
            return '(none)';
        }

        $canonical = json_encode($credentials, JSON_UNESCAPED_UNICODE);

        return 'set:'.substr(hash('sha256', is_string($canonical) ? $canonical : ''), 0, 8);
    }
}
