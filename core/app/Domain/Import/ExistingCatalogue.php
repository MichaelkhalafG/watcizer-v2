<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Do we already sell this?" — the gate that stops the importer re-adding a product Watchizer has.
 *
 * ── The defect this exists for ──────────────────────────────────────────────────────────────
 *
 * The first real run imported `BF-14874 · "ساعة Tommy HIlfiger رجالي 1791400"` — no SKU, no
 * category, machine-translated, poor cover — next to the catalogue's own `1791400`, a complete
 * record of the same watch. Measured across the 2 255 rows of that run: **43 such pairs**.
 *
 * ── The two rules, in order ─────────────────────────────────────────────────────────────────
 *
 *  1. **SKU.** The row's `sku`, normalised, against the existing products' `sku`, `model_number`
 *     and `wa_code`, normalised. Legacy rows carry the manufacturer's reference in all three, and
 *     normalising is what makes `Ar 0389` and `AR0389` the same code.
 *
 *  2. **Model number in the TITLE**, only when rule 1 found nothing — which is the common case,
 *     because the source usually has no SKU and writes the code in the name instead.
 *
 * ── What counts as a model number ───────────────────────────────────────────────────────────
 *
 * Measured on the 544 distinct identity codes in this catalogue, not chosen by taste:
 *
 *     length   4:5   5:44   6:48   7:189   8:17   9:225   10+:16
 *     shape    178 pure digits · 339 letters+digits · 27 with NO digit
 *     0 codes look like a year · 4 codes are claimed by more than one product
 *
 * So: **at least {@see self::MIN_TOKEN} characters, containing at least one digit**, alphanumeric
 * after normalisation.
 *
 *   • the length floor gives up the five 4-character codes (0.9 % of the index) and buys the
 *     removal of the densest false-positive band in a product title — `2024`, `40MM`, `18K`,
 *     `2PCS`. Measured cost on the real data: **zero** — a looser substring search found nothing
 *     extra at four characters;
 *   • the digit requirement drops the 27 letter-only codes as targets. They are words, and a word
 *     collides with ordinary title text;
 *   • a four-digit year is refused explicitly. No code in this catalogue is one, so it costs
 *     nothing and stops the single most likely coincidence.
 *
 * ── False positives, and why they are structurally hard rather than merely unlikely ─────────
 *
 * Matching two DIFFERENT products is worse than leaving a duplicate, so two refusals sit on top:
 *
 *   • a code claimed by more than one existing product is **never a target** — it cannot identify
 *     one of them (4 such codes here: `1792157`, `1514217`, `AP0012`, `AP0013`);
 *   • a title whose tokens point at **two different products matches nothing at all**.
 *
 * The residual risk is a code appearing coincidentally in an unrelated title. It cannot be argued
 * to zero, which is why every match is written to the durable report with both codes and the rule
 * that fired — if one is ever wrong, that file is how anybody finds out.
 *
 * ── One rule this deliberately does NOT have ────────────────────────────────────────────────
 *
 * Splitting a letter-run off a digit-run — `Men1791711` → `Men 1791711` — would have caught four
 * more, and would also have destroyed `HWESG951320` and `BAGQ93378`, which are genuinely three
 * letters and then digits. So {@see self::tokens()} ADDS the digit-run as an extra candidate and
 * never replaces the whole token; if the two disagree, the ambiguity refusal fires.
 */
final class ExistingCatalogue
{
    /** The length floor for a title token. See the distribution in the class docblock. */
    public const MIN_TOKEN = 5;

    /** The floor for a SKU-to-SKU comparison, which is a stronger signal than a title token. */
    private const MIN_SKU = 4;

    /**
     * `normalised code => product id`, built once. Codes claimed by more than one product are
     * absent by construction rather than filtered at lookup time.
     *
     * @var array<string, int>|null
     */
    private ?array $index = null;

    /**
     * Identity of an existing product, for the report.
     *
     * @var array<int, array{wa_code: string, sku: string|null, title: string}>
     */
    private array $identities = [];

    /**
     * The product this row already IS, or null.
     *
     * @return array{id: int, code: string, rule: string, wa_code: string, sku: string|null, title: string}|null
     */
    public function match(SourceRow $row): ?array
    {
        $this->build();

        $sku = self::normalise($row->sku);
        if ($sku !== '' && strlen($sku) >= self::MIN_SKU && isset($this->index[self::slot($sku)])) {
            return $this->describe($this->index[self::slot($sku)], $sku, 'sku');
        }

        $hits = [];
        foreach (self::tokens($row->titleEn) as $token) {
            if (isset($this->index[self::slot($token)])) {
                $hits[$this->index[self::slot($token)]] = $token;
            }
        }

        // Two different products means we do not know which, so we do not guess.
        if (count($hits) !== 1) {
            return null;
        }

        $id = array_key_first($hits);

        return $this->describe($id, $hits[$id], 'title-model');
    }

    /**
     * Model-number-shaped tokens in a title.
     *
     * @return list<string>
     */
    public static function tokens(string $title): array
    {
        // `Ar 0389` and `AR0389` are one code written two ways, so a short letter-run is joined to
        // the digits that follow it before anything is split.
        $joined = (string) preg_replace('/\b([A-Za-z]{1,4})\s+(\d{3,})\b/u', '$1$2', $title);

        /*
         * Collected as a LIST with a separate seen-set, not as a map keyed by the token: PHP turns
         * a numeric-looking array key into an int, so `array_keys()` on `['1791400' => true]`
         * hands back `int(1791400)` and the caller's `isset($index[$token])` misses every
         * pure-digit code — which is most of them.
         */
        $out = [];
        $seen = [];
        foreach (preg_split('/[^A-Za-z0-9]+/u', $joined) ?: [] as $part) {
            $code = strtoupper($part);

            if (strlen($code) < self::MIN_TOKEN || preg_match('/\d/', $code) !== 1) {
                continue;
            }
            if (preg_match('/^(19|20)\d{2}$/', $code) === 1) {
                continue;
            }

            foreach (self::candidatesFor($code) as $candidate) {
                if (! isset($seen[$candidate])) {
                    $seen[$candidate] = true;
                    $out[] = $candidate;
                }
            }
        }

        return $out;
    }

    /**
     * The token itself, plus its digit-run when a WORD is glued to the front of it.
     *
     * ADDITIVE, never a replacement: splitting `BAGQ93378` and `HWESG951320` — two real in-house
     * codes that are genuinely letters then digits — would have destroyed them. If the whole token
     * and its digit-run point at different products, the caller refuses the match outright.
     *
     * @return list<string>
     */
    private static function candidatesFor(string $code): array
    {
        $out = [$code];

        if (preg_match('/^[A-Z]{3,}(\d{5,})$/', $code, $m) === 1) {
            $out[] = $m[1];
        }

        return $out;
    }

    /**
     * The index key for a code.
     *
     * PHP casts a numeric-looking array key to an INT, so `$index['1791400']` and `$index[1791400]`
     * are the same slot — which works only as long as every read and write casts the same way. A
     * prefix makes the key genuinely a string and removes the rule from everybody's head.
     */
    private static function slot(string $code): string
    {
        return 'c:'.$code;
    }

    /** Uppercase, alphanumerics only. `Ar 0389` and `ar-0389` are the same code. */
    public static function normalise(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /**
     * @return array{id: int, code: string, rule: string, wa_code: string, sku: string|null, title: string}
     */
    private function describe(int $id, string $code, string $rule): array
    {
        $identity = $this->identities[$id] ?? ['wa_code' => '#'.$id, 'sku' => null, 'title' => ''];

        return [
            'id' => $id,
            'code' => $code,
            'rule' => $rule,
            'wa_code' => $identity['wa_code'],
            'sku' => $identity['sku'],
            'title' => $identity['title'],
        ];
    }

    /**
     * Read the catalogue ONCE.
     *
     * `import_ref IS NULL` is the whole definition of "already ours": a product this importer made
     * is excluded, because matching against our own output is what `import_ref` already does and
     * doing it twice would make the second run of a file report every row as a duplicate of itself.
     */
    private function build(): void
    {
        if ($this->index !== null) {
            return;
        }

        $claims = [];
        $this->index = [];

        $rows = DB::table('catalog_products as p')
            ->whereNull('p.import_ref')
            ->whereNull('p.deleted_at')
            ->leftJoin('catalog_product_translations as t', function (JoinClause $join): void {
                $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
            })
            ->get(['p.id', 'p.sku', 'p.model_number', 'p.wa_code', 't.title']);

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');

            $this->identities[$id] = [
                'wa_code' => Row::nstr($row, 'wa_code') ?? ('#'.$id),
                'sku' => Row::nstr($row, 'sku'),
                'title' => Coerce::str(Row::nstr($row, 'title')),
            ];

            /*
             * `model_number` is STILL matched here, on purpose, after the item-4 merge
             * (2026-09-19) stopped the dashboard writing it.
             *
             * The column keeps every value that was ever in it, including the fifteen that
             * disagree with their product's `sku` and were deliberately not overwritten. Those are
             * exactly the codes an incoming supplier row is most likely to carry — so dropping
             * them from the duplicate check would make the importer re-create products it already
             * has. A matcher should read every code the catalogue has ever known.
             */
            foreach (['sku', 'model_number', 'wa_code'] as $column) {
                $code = self::normalise(Row::nstr($row, $column));
                if ($code !== '' && strlen($code) >= self::MIN_SKU) {
                    $claims[self::slot($code)][$id] = true;
                }
            }
        }

        foreach ($claims as $slot => $ids) {
            if (count($ids) === 1) {
                $this->index[(string) $slot] = array_key_first($ids);
            }
        }
    }
}
