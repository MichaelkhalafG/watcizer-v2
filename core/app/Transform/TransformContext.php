<?php

namespace App\Transform;

use App\Models\Storefront\Storefront;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;
use stdClass;

/**
 * Everything a step needs: the read-only legacy source, the clean-side writer,
 * the id map, the options, and small shared caches (translations, families).
 */
final class TransformContext
{
    /**
     * The PRIMARY storefront (Watchizer). A step that writes one storefront's rows means this one.
     *
     * The per-storefront steps (15–19) REASSIGN it around their loop and restore it afterwards, so
     * that `CategoryNodes`, the placement writes and the reconciliation keep reading one field
     * instead of every one of them growing a storefront parameter. {@see self::eachStorefront()}
     * is the only sanctioned way to do that — it restores the value even if the body throws.
     */
    public int $storefrontId = Storefront::WATCHIZER_ID;

    /** @var list<int>|null memoised per run: the active storefront ids, lowest first */
    private ?array $activeStorefronts = null;

    /**
     * Whether `storefront_product` was EMPTY when this run started — i.e. whether this run is a
     * fresh rebuild (switch night) rather than an additive rehearsal.
     *
     * Measured by the command before any step runs, so it is a fact about the database and not a
     * claim by a step. The reconciliation needs it to tell two different things apart: on a fresh
     * rebuild every placement row is an INSERT, so "every row is visible" is a real assertion; on
     * an additive re-run a hidden row is the team's own work, and asserting it away would make the
     * insert-only rule unprovable. {@see Reconciliation}
     */
    public bool $freshRebuild = false;

    /** @var array<int, string> product id => family, filled by step 6 (or lazily from catalog_products) */
    private array $families = [];

    /** @var list<array{code: string, entity: string, id: string, legacy: string, clean: string}> rows for diff.csv */
    public array $diff = [];

    /** @var array<string, array<int, array<string, string>>> cache: "table" => id => locale => name */
    private array $translationCache = [];

    /**
     * @param  array<string, mixed>  $config  config('transform')
     */
    public function __construct(
        public readonly LegacySource $legacy,
        public readonly Connection $db,
        public readonly Writer $writer,
        public readonly IdMap $idMap,
        public readonly TransformOptions $options,
        public readonly array $config,
    ) {}

    public function chunk(): int
    {
        return $this->options->chunk;
    }

    /** Is the loop currently pointing at the PRIMARY storefront (Watchizer)? */
    public function isPrimaryStorefront(): bool
    {
        return $this->storefrontId === Storefront::WATCHIZER_ID;
    }

    /**
     * Remember a legacy id → category node id pair — for the PRIMARY storefront ONLY.
     *
     * `core_transform_id_map` is keyed `(source_table, source_id, target_table)` with no storefront
     * column, so writing Brand Fashion's node ids under `category_types`/`sub_types:N` would
     * OVERWRITE Watchizer's and every later lookup would resolve to the wrong tree. Widening the
     * key was the alternative and was rejected: mirrored nodes already have a natural key
     * — `(storefront_id, legacy_source, legacy_id, legacy_parent_id)`, which is exactly what
     * {@see CategoryNodes::find()} looks them up by — so the map would be storing something it is
     * not needed for, and the map's own reconciliation count would change for no gain.
     *
     * So: the primary storefront keeps its id-map rows byte-for-byte as before, and a mirrored
     * storefront resolves by natural key.
     */
    public function rememberNode(string $sourceKey, int $legacyId, int $nodeId): void
    {
        if (! $this->isPrimaryStorefront()) {
            return;
        }
        $this->idMap->remember($sourceKey, $legacyId, 'storefront_categories', $nodeId);
    }

    /** The id-map's answer for a category node — null for a mirrored storefront, by design. */
    public function nodeId(string $sourceKey, int $legacyId): ?int
    {
        return $this->isPrimaryStorefront()
            ? $this->idMap->get($sourceKey, $legacyId, 'storefront_categories')
            : null;
    }

    /**
     * The active storefront ids, lowest first — read once per run.
     *
     * From the DATABASE, never from a constant: a storefront can be switched off from the settings
     * screen, and a transform that kept writing placements for a dead channel would be inventing
     * rows nobody asked for. Memoised because step 14 has already settled the list by the time any
     * iterating step runs, and three steps ask for it.
     *
     * @return list<int>
     */
    public function activeStorefrontIds(): array
    {
        if ($this->activeStorefronts === null) {
            $this->activeStorefronts = Storefront::activeIds();
        }

        return $this->activeStorefronts;
    }

    /**
     * Run `$body` once per ACTIVE storefront with `$this->storefrontId` pointing at it.
     *
     * The primary storefront is always restored afterwards — including when the body throws —
     * because a step that left `storefrontId` pointing at Brand Fashion would silently write every
     * later step's rows to the wrong channel, and that is the kind of bug that looks like data
     * corruption rather than like a bug.
     *
     * @param  callable(int, bool): void  $body  (storefront id, is the primary storefront)
     */
    public function eachStorefront(callable $body): void
    {
        $primary = $this->storefrontId;
        try {
            foreach ($this->activeStorefrontIds() as $id) {
                $this->storefrontId = $id;
                $body($id, $id === $primary);
            }
        } finally {
            $this->storefrontId = $primary;
        }
    }

    /** @return array<string, mixed> */
    public function configArray(string $key): array
    {
        return Config::stringKeyed($this->config[$key] ?? null);
    }

    /**
     * Integer-keyed config map (e.g. orphan_sub_type_parents: sub_type_id => category_type_id).
     *
     * @return array<int, int>
     */
    public function configIntMap(string $key): array
    {
        $out = [];
        $v = $this->config[$key] ?? null;
        if (is_array($v)) {
            foreach ($v as $k => $val) {
                if (is_int($k) && is_int($val)) {
                    $out[$k] = $val;
                }
            }
        }

        return $out;
    }

    public function configInt(string $key): int
    {
        $v = $this->config[$key] ?? null;

        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }

    /**
     * Legacy lookup translations as id => ['ar' => name, 'en' => name]. Only ar/en are
     * loaded (the storefront locales); any other locale is ignored and counted by A-01.
     *
     * @return array<int, array<string, string>>
     */
    public function legacyTranslations(string $table, string $fk, string $nameColumn): array
    {
        $cacheKey = "$table:$nameColumn";
        if (isset($this->translationCache[$cacheKey])) {
            return $this->translationCache[$cacheKey];
        }

        $out = [];
        $rows = $this->legacy->table($table)
            ->select([$fk, 'locale', $nameColumn])
            ->whereIn('locale', ['ar', 'en'])
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $out[Row::int($row, $fk)][Row::str($row, 'locale')] = Row::nstr($row, $nameColumn) ?? '';
        }

        return $this->translationCache[$cacheKey] = $out;
    }

    /** EN name of a translated legacy lookup row, '' when absent. */
    public function legacyName(string $table, string $fk, string $nameColumn, int $id, string $locale = 'en'): string
    {
        return $this->legacyTranslations($table, $fk, $nameColumn)[$id][$locale] ?? '';
    }

    public function setFamily(int $productId, string $family): void
    {
        $this->families[$productId] = $family;
    }

    /** Family of a product; falls back to the clean table when step 6 did not run in this process. */
    public function family(int $productId): string
    {
        $this->primeFamilies();

        return $this->families[$productId] ?? throw new RuntimeException("No family known for product $productId — run step 6 first.");
    }

    /** @return array<int, string> */
    public function families(): array
    {
        $this->primeFamilies();

        return $this->families;
    }

    /**
     * Load the family of every clean product once. Priming used to go through family(-1), which
     * threw for the sentinel id whenever step 6 had not run in the same process — so any
     * `--only=` run that skipped step 6 crashed in the reconciliation (found 2026-09-08 while
     * writing the one-primary regression test, which runs `--only=19`).
     */
    private function primeFamilies(): void
    {
        if ($this->families !== []) {
            return;
        }
        foreach ($this->db->table('catalog_products')->select(['id', 'family'])->orderBy('id')->cursor() as $row) {
            $this->families[Row::int($row, 'id')] = Row::str($row, 'family');
        }
    }

    public function diff(string $code, string $entity, int|string $id, string $legacy, string $clean): void
    {
        $this->diff[] = ['code' => $code, 'entity' => $entity, 'id' => (string) $id, 'legacy' => $legacy, 'clean' => $clean];
    }

    /**
     * Read a legacy table in id-ordered chunks with an explicit select list.
     *
     * @param  list<string>  $columns
     * @param  callable(Collection<int, stdClass>): void  $callback
     */
    public function chunkLegacy(string $table, array $columns, callable $callback): void
    {
        $this->legacy->table($table)->select($columns)->orderBy('id')->chunkById($this->chunk(), function (Collection $rows) use ($callback): void {
            /** @var Collection<int, stdClass> $rows */
            $callback($rows);
        });
    }

    /**
     * ids present in a legacy table (e.g. users), as a set.
     *
     * @return array<int, true>
     */
    public function legacyIdSet(string $table): array
    {
        $set = [];
        foreach ($this->legacy->table($table)->select(['id'])->orderBy('id')->cursor() as $row) {
            $set[Row::int($row, 'id')] = true;
        }

        return $set;
    }
}
