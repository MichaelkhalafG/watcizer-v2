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
    public int $storefrontId = Storefront::WATCHIZER_ID;

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
        if ($this->families === []) {
            foreach ($this->db->table('catalog_products')->select(['id', 'family'])->orderBy('id')->cursor() as $row) {
                $this->families[Row::int($row, 'id')] = Row::str($row, 'family');
            }
        }

        return $this->families[$productId] ?? throw new RuntimeException("No family known for product $productId — run step 6 first.");
    }

    /** @return array<int, string> */
    public function families(): array
    {
        if ($this->families === []) {
            $this->family(-1);
        }

        return $this->families;
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
