<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Compat\Diff\JsonDiff;
use App\Compat\Diff\ProbeSubject;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Throwable;

/**
 * The wave-4B EDITING surface, measured against the compat contract (2026-09-12).
 *
 * ── Why `compat:diff` cannot do this ─────────────────────────────────────────────────────────
 *
 * `compat:diff` sends the SAME request to both hosts and compares the answers; every case it has
 * is symmetric by construction. What wave 4B added is a surface that exists on ONE side only — a
 * dashboard that writes the CLEAN tables. There is no legacy request to pair it with, so the
 * question is a different one:
 *
 *   after the dashboard writes, does the compat payload change in EXACTLY the way the contract
 *   allows, does it change AT ALL (or is a stale cache still being served), and is the legacy
 *   side still untouched?
 *
 * ── The shape of one scenario ────────────────────────────────────────────────────────────────
 *
 *   snapshot → baseline read (both hosts, real HTTP, must be 200) → one dashboard write
 *            → re-read both → classify every changed JSON path → RESTORE → re-read and require
 *              the payload back to the baseline byte for byte.
 *
 * A changed path counts as sanctioned only if the scenario declares it; anything else is
 * unexplained and the command fails. A real difference must never be absorbed to make a run green.
 *
 * ── Two rules this command learned the hard way (first run, 2026-09-12) ──────────────────────
 *
 *  1. **Every scenario restores before the next one starts.** The first version hid the product in
 *     one scenario and measured the next one against an already-404 baseline, then reported "the
 *     payload did not change" — a finding manufactured by the probe itself.
 *  2. **A dashboard write is sent with the payload the SCREEN sends, never a minimal one.** The
 *     product form folds a flat payload into a full storefront section, so a PUT without
 *     `storefronts` is read as "no categories chosen" and the writer correctly DELETES the
 *     product's placements. The first version did exactly that to a live product. Every write
 *     here is built from a snapshot of the current state with ONE field changed, and a redirect
 *     carrying validation errors is treated as a failed write rather than a 302 success.
 *
 * ── The one honest limitation ────────────────────────────────────────────────────────────────
 *
 * The READS are real HTTP against both booted hosts. The WRITE is dispatched through core's own
 * HTTP kernel in this process (`Request::create` + a started session + a valid CSRF token, so
 * routing, auth, the ability gates, validation, the writers and the cache bump all run) rather
 * than over a socket, because the dashboard uses session auth and no local account has a password
 * this command may know. It exercises the whole server-side write path; not the browser's.
 */
final class CompatEditProbeCommand extends Command
{
    protected $signature = 'compat:edit-probe
        {--legacy= : base URL of the legacy application (required)}
        {--compat= : base URL of the core host (required)}
        {--product= : product id to probe (default: the lowest product visible on storefront 1)}
        {--pace=1100 : milliseconds to wait before every legacy request (legacy throttles 60/min)}';

    protected $description = 'Probe the wave-4B dashboard write surface against the compat contract (reads over real HTTP)';

    /** @var list<array{scenario: string, path: string, changed: int, unexplained: list<string>, note: string}> */
    private array $results = [];

    private ?ProbeSubject $subject = null;

    public function handle(HttpKernel $kernel): int
    {
        $legacy = rtrim((string) $this->option('legacy'), '/');
        $compat = rtrim((string) $this->option('compat'), '/');
        if ($legacy === '' || $compat === '') {
            $this->error('--legacy and --compat are both required: this probe is only meaningful over real HTTP.');

            return self::FAILURE;
        }

        $productId = Coerce::int($this->option('product'));
        if ($productId === 0) {
            $productId = Coerce::int(DB::table('storefront_product')
                ->where('storefront_id', 1)->where('is_visible', true)
                ->orderBy('product_id')->value('product_id'));
        }
        if ($productId === 0) {
            $this->error('no VISIBLE product on storefront 1, so the compat endpoints have nothing to serve.');

            return self::FAILURE;
        }

        $admin = User::find(1);
        if ($admin === null) {
            $this->error('user 1 does not exist — the probe needs an account with the admin role.');

            return self::FAILURE;
        }
        Auth::login($admin);

        $subject = ProbeSubject::read($productId);
        if ($subject === null) {
            $this->error("product {$productId} has no storefront_product row on storefront 1.");

            return self::FAILURE;
        }
        $this->subject = $subject;

        $detail = "api/products/{$productId}";
        $list = 'api/all_product';

        $this->line('');
        $this->line("legacy : {$legacy}");
        $this->line("compat : {$compat}");
        $this->line(sprintf(
            'product: %d (visible=%s featured=%s sort=%d, %d placement(s) on storefront 1)',
            $productId,
            var_export($subject->isVisible, true),
            var_export($subject->isFeatured, true),
            $subject->sortOrder,
            count($subject->categoryIds),
        ));
        $this->line('');

        try {
            // 1. `is_featured` — dashboard-owned and absent from the legacy payload, so the compat
            //    answer must not move at all. A change would mean core leaking a column the legacy
            //    contract never had.
            $this->scenario(
                'featured-toggle', $legacy, $compat, $detail, $productId,
                fn (): array => $this->placement($kernel, $productId, [
                    'is_featured' => ! $subject->isFeatured,
                ]),
                'is_featured is dashboard-owned and absent from the legacy payload: the compat answer must not move',
            );

            // 2. `sort_order` — same shape on the DETAIL payload.
            $this->scenario(
                'sort-order', $legacy, $compat, $detail, $productId,
                fn (): array => $this->placement($kernel, $productId, [
                    'sort_order' => $subject->sortOrder + 7,
                ]),
                'sort_order is dashboard-owned and absent from the legacy detail payload',
            );

            // 3. `is_visible = false` — the one dashboard write with a VISIBLE consequence for the
            //    compat contract: the §3.3 rule drops the product from the listing while legacy
            //    keeps it, because nothing writes to legacy. The assertion that matters is that it
            //    happens AT ALL: a stale cache would keep serving the product.
            $this->scenario(
                'hide-product', $legacy, $compat, $list, $productId,
                fn (): array => $this->placement($kernel, $productId, ['is_visible' => false]),
                'hiding on storefront 1 removes the product from the compat listing; legacy keeps it (no legacy write)',
                expectCompatChange: true,
                // `all_product` answers a BARE ARRAY of 530 rows (measured), so there is no
                // `$.data` wrapper to pair positionally; id-pairing at the root is what reports a
                // removed product as one missing row instead of 530 shifted ones.
                positional: [],
            );

            /*
             * 4. A LOOKUP rename — the narrowest content edit the dashboard has, and one whose
             *    payload is complete by construction (a lookup row IS its two names). Colours are
             *    part of `catalog/meta`, so the edit has to show up there.
             *
             *    The product FORM is deliberately NOT driven here: it is a full-REPLACE contract,
             *    so a payload short of the whole form clears what it omits. That is faithful to
             *    the screen, which always submits everything, but it makes the form unusable as a
             *    probe surface without rebuilding the screen's entire payload first — recorded as
             *    an observation rather than papered over.
             */
            $this->scenario(
                'colour-rename', $legacy, $compat, 'api/catalog/meta', $productId,
                fn (): array => $this->lookupRename($kernel, 'colors', $subject->colourId, $subject->colourAr.' probe'),
                'a dashboard lookup rename reaches catalog/meta; the legacy side is untouched',
                expectCompatChange: true,
                // MEASURED, not guessed: `catalog/meta` projects one colour into three places —
                // the lookup table with its translation rows, and the dial/band colour lists. All
                // three moved together, which is the property worth asserting: a rename does not
                // reach one projection and miss another.
                allowedPaths: [
                    '$.tables.colors[*].translations[*].color_name',
                    '$.dial_colors[*].name_ar',
                    '$.band_colors[*].name_ar',
                ],
            );

            /*
             * 5. A CATEGORY rename on the PRIMARY tree — allowed pre-switch (`mayEditTree(1)`),
             *    and category names travel into `catalog/meta` too.
             */
            $this->scenario(
                'category-rename', $legacy, $compat, 'api/catalog/meta', $productId,
                fn (): array => $this->categoryRename($kernel, $subject->nodeId, $subject->nodeAr.' probe', $subject->nodeEn),
                'a dashboard category rename reaches catalog/meta; the legacy side is untouched',
                expectCompatChange: true,
                // Measured the same way: the node's name appears in the lookup table's
                // translation rows and in the flat `sub_types` list.
                allowedPaths: [
                    '$.tables.subTypes[*].translations[*].sub_type_name',
                    '$.sub_types[*].name_ar',
                ],
            );

        } finally {
            $this->restore($kernel, $productId);
        }

        return $this->report($productId);
    }

    /**
     * One scenario, always restored before the next begins.
     *
     * @param  callable(): array{status: int, errors: list<string>}  $write
     * @param  list<string>  $allowedPaths
     * @param  list<string>  $positional
     */
    private function scenario(
        string $name,
        string $legacy,
        string $compat,
        string $path,
        int $productId,
        callable $write,
        string $note,
        bool $expectCompatChange = false,
        array $allowedPaths = [],
        array $positional = [],
    ): void {
        $unexplained = [];

        $legacyBefore = $this->fetch($legacy, $path, pace: true);
        $compatBefore = $this->fetch($compat, $path);

        // A baseline that is not 200 makes every later comparison meaningless — that is how the
        // first version of this command manufactured a finding.
        if ($compatBefore['status'] !== 200 || $legacyBefore['status'] !== 200) {
            $unexplained[] = sprintf(
                'baseline read is not 200 on both hosts (legacy %d, compat %d) — the scenario cannot measure anything',
                $legacyBefore['status'], $compatBefore['status'],
            );
        }

        $result = $write();
        if ($result['status'] < 200 || $result['status'] >= 400) {
            $unexplained[] = "the dashboard write failed with HTTP {$result['status']}";
        }
        foreach ($result['errors'] as $field) {
            $unexplained[] = "the dashboard write was REFUSED at field [{$field}] (a 302 back to the form, not a save)";
        }

        $legacyAfter = $this->fetch($legacy, $path, pace: true);
        $compatAfter = $this->fetch($compat, $path);

        $compatDrift = JsonDiff::compare($compatBefore['json'], $compatAfter['json'], $positional);
        $legacyDrift = JsonDiff::compare($legacyBefore['json'], $legacyAfter['json'], $positional);

        // The legacy side must NEVER move: the dashboard writes clean tables only (AGENTS §3).
        foreach ($legacyDrift as $finding) {
            $unexplained[] = 'LEGACY MOVED '.(string) $finding['path'];
        }

        if ($expectCompatChange && $compatDrift === [] && $compatBefore['status'] === 200) {
            $unexplained[] = 'the compat payload did NOT change — a write the contract says is visible was not served (stale cache?)';
        }
        if (! $expectCompatChange) {
            foreach ($compatDrift as $finding) {
                $unexplained[] = 'COMPAT MOVED '.(string) $finding['path'];
            }
        } elseif ($allowedPaths !== []) {
            foreach ($compatDrift as $finding) {
                $normalised = JsonDiff::normalise((string) $finding['path']);
                // A write bumps the row's own `updated_at`, wherever the payload projects it —
                // the same class `compat:diff` absorbs as D-17. Declared, not filtered silently.
                if (str_ends_with($normalised, '.updated_at')) {
                    continue;
                }
                if (! in_array($normalised, $allowedPaths, true)) {
                    $unexplained[] = 'COMPAT MOVED '.(string) $finding['path'].' (outside what this scenario may change)';
                }
            }
        }

        // Put it back, then prove the payload is the baseline again.
        $this->restore(app(HttpKernel::class), $productId);
        $compatRestored = $this->fetch($compat, $path);
        $restoreDrift = JsonDiff::compare($compatBefore['json'], $compatRestored['json'], $positional);
        foreach ($restoreDrift as $finding) {
            /*
             * A restore is itself a write, so the row's own `updated_at` moves — the payload is
             * back to the baseline in every field that carries meaning. Declared here rather than
             * filtered silently: this is the same class of deviation `compat:diff` absorbs as
             * D-01, and nothing else is allowed to differ.
             */
            if (str_ends_with(JsonDiff::normalise((string) $finding['path']), '.updated_at')) {
                continue;
            }
            $unexplained[] = 'NOT RESTORED '.(string) $finding['path'];
        }

        $this->results[] = [
            'scenario' => $name,
            'path' => $path,
            'changed' => count($compatDrift),
            'unexplained' => $unexplained,
            'note' => $note,
        ];

        $this->line(sprintf(
            '  %-16s %s  (%d changed path(s) in the compat payload, write HTTP %d)',
            $name,
            $unexplained === [] ? '<info>OK</info>' : '<error>FINDING</error>',
            count($compatDrift),
            $result['status'],
        ));
        foreach ($unexplained as $u) {
            $this->line('      → '.$u);
        }
    }

    /** The subject, or a loud failure: every write path needs it and it is read before any write. */
    private function subject(): ProbeSubject
    {
        $subject = $this->subject;
        if ($subject === null) {
            throw new RuntimeException('the probe subject was never read — this is a bug in the command.');
        }

        return $subject;
    }

    /** @return array{status: int, json: mixed, bytes: int} */
    private function fetch(string $base, string $path, bool $pace = false): array
    {
        if ($pace) {
            usleep(((int) $this->option('pace')) * 1000);
        }
        $response = Http::withHeaders([
            'Api-Code' => config()->string('compat.api_key'),
            'Accept' => 'application/json, text/plain, */*',
        ])->timeout(30)->get($base.'/'.ltrim($path, '/'));

        $body = $response->body();

        return ['status' => $response->status(), 'json' => json_decode($body, true), 'bytes' => strlen($body)];
    }

    /**
     * The placement screen's endpoint, with the whole form's payload and ONE field overridden.
     *
     * @param  array<string, mixed>  $changes
     * @return array{status: int, errors: list<string>}
     */
    private function placement(HttpKernel $kernel, int $productId, array $changes): array
    {
        $subject = $this->subject();
        $visible = array_key_exists('is_visible', $changes) ? (bool) $changes['is_visible'] : $subject->isVisible;
        $featured = array_key_exists('is_featured', $changes) ? (bool) $changes['is_featured'] : $subject->isFeatured;
        $sort = array_key_exists('sort_order', $changes) ? Coerce::int($changes['sort_order']) : $subject->sortOrder;

        return $this->dashboard($kernel, "/manage/storefronts/1/placement/{$productId}", 'PUT', [
            'is_visible' => $visible ? '1' : '0',
            'is_featured' => $featured ? '1' : '0',
            'sort_order' => (string) $sort,
            'category_ids' => $subject->categoryIdsAsStrings(),
            'primary_category_id' => $subject->primaryCategoryId === null ? null : (string) $subject->primaryCategoryId,
        ]);
    }

    /**
     * Dispatch a dashboard request through core's HTTP kernel with a started session and a valid
     * CSRF token, and READ THE ERROR BAG: the dashboard answers a refused save with a 302 back to
     * the form, so a status check alone would call a refusal a success.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, errors: list<string>}
     */
    private function dashboard(HttpKernel $kernel, string $uri, string $method, array $payload): array
    {
        try {
            $session = app('session.store');
            $session->start();
            $request = Request::create($uri, $method, array_merge(['_token' => $session->token()], $payload));
            $request->setLaravelSession($session);
            $response = $kernel->handle($request);

            /*
             * The dashboard answers a REFUSED save with a 302 back to the form, so the status
             * alone would call a refusal a success. `ViewErrorBag::keys()` is the narrow read:
             * which fields were refused, without asking for message text nobody here compares.
             */
            $errors = [];
            $bag = $session->get('errors');
            if ($bag instanceof ViewErrorBag) {
                foreach ($bag->keys() as $field) {
                    $errors[] = Coerce::str($field);
                }
            }
            $session->forget('errors');

            return ['status' => $response->getStatusCode(), 'errors' => $errors];
        } catch (Throwable $e) {
            $this->line('      → write threw: '.mb_substr($e->getMessage(), 0, 140));

            return ['status' => 0, 'errors' => []];
        }
    }

    /**
     * The lookups screen's own endpoint — the whole form for that screen is the two names.
     *
     * @return array{status: int, errors: list<string>}
     */
    private function lookupRename(HttpKernel $kernel, string $list, int $id, string $arabic): array
    {
        $subject = $this->subject();
        if ($id === 0) {
            return ['status' => 0, 'errors' => ['no lookup row to rename']];
        }

        /*
         * `extra.hex` travels WITH the names, because this endpoint is a full-replace contract
         * too: a PUT without the hex stores null, and `catalog/meta` then serves
         * `color_value: null` where legacy has `#111111`. The harness caught exactly that when an
         * earlier version of this probe omitted it — the real screen always submits the colour.
         */
        return $this->dashboard($kernel, "/manage/lookups/{$list}/{$id}", 'PUT', [
            'name' => ['ar' => $arabic, 'en' => $subject->colourEn],
            'extra' => ['hex' => $subject->colourHex],
        ]);
    }

    /**
     * The category screen's own endpoint — the whole form is the two names plus the flags.
     *
     * @return array{status: int, errors: list<string>}
     */
    private function categoryRename(HttpKernel $kernel, int $nodeId, string $arabic, string $english): array
    {
        if ($nodeId === 0) {
            return ['status' => 0, 'errors' => ['no category node to rename']];
        }

        return $this->dashboard($kernel, "/manage/storefronts/1/categories/{$nodeId}", 'PUT', [
            'name' => ['ar' => $arabic, 'en' => $english],
            'is_active' => '1',
        ]);
    }

    /** Put every subject back exactly as the snapshot found it. */
    private function restore(HttpKernel $kernel, int $productId): void
    {
        $subject = $this->subject();
        $this->placement($kernel, $productId, []);
        if ($subject->colourId !== 0) {
            $this->lookupRename($kernel, 'colors', $subject->colourId, $subject->colourAr);
        }
        if ($subject->nodeId !== 0) {
            $this->categoryRename($kernel, $subject->nodeId, $subject->nodeAr, $subject->nodeEn);
        }
    }

    private function report(int $productId): int
    {
        $unexplained = 0;
        foreach ($this->results as $r) {
            $unexplained += count($r['unexplained']);
        }

        $dir = storage_path('compat-edit-probe/'.date('Ymd-His'));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/report.json', (string) json_encode([
            'date' => date('c'),
            'product' => $productId,
            'scenarios' => $this->results,
            'unexplained' => $unexplained,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line('');
        $this->table(
            ['scenario', 'read path', 'changed paths', 'unexplained', 'what it means'],
            array_map(fn (array $r): array => [
                $r['scenario'], $r['path'], $r['changed'], count($r['unexplained']), mb_substr($r['note'], 0, 58),
            ], $this->results),
        );
        $this->line("report: {$dir}/report.json");
        $this->line('');

        if ($unexplained > 0) {
            $this->error("FINDINGS: {$unexplained} unexplained difference(s) — see above.");

            return self::FAILURE;
        }

        $this->info('PASS — every difference the dashboard made is one the contract sanctions, the legacy side never moved, and each scenario restored the catalogue.');

        return self::SUCCESS;
    }
}
