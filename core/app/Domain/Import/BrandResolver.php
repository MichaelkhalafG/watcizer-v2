<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Catalog\PreSwitch;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Which of our 77 brands is this product's, read out of its NAME (wave 4D importers).
 *
 * ── Why the name, and why this is not as bad as it sounds ────────────────────────────────────
 *
 * The WooCommerce export's `Brands` column is **100% empty** — measured, 0 of 8 614 rows. The
 * brand exists in exactly one place: the front of the title.
 *
 *     "Tommy Hilfiger Watch For Men 1791594"   → Tommy Hilfiger
 *     "EMPORIO ARMANI Men's Watch AR60079"     → Emporio Armani
 *     "Generic Women sunglasses Inspired By Gucci sn256"   → Generic, NOT Gucci
 *
 * That last one is the case that decides the algorithm. "Inspired By Gucci" is a description of a
 * lookalike; treating it as the brand would put counterfeit-adjacent products under a luxury house
 * in our own catalogue. So the match is anchored to the **start of the title** and the text after
 * "inspired by" is cut off before matching.
 *
 * ── The developer's rule: never refuse a row for its brand ───────────────────────────────────
 *
 * `catalog_products.brand_id` is NOT NULL, so every row needs one. Anything unmatched gets a brand
 * literally named **Generic** — created once if it does not exist — and the product is MARKED as
 * missing its brand, so the team can filter for them and reassign in bulk later. A row is never
 * dropped for this (developer, 2026-09-14).
 *
 * Every name that failed to match is counted and reported, because the top of that list is the
 * answer to "which brands should we add?" — measured on this file: `Mini Focus` (237) and
 * `Naviforce` are real brands of theirs that our 77 do not have.
 */
final class BrandResolver
{
    public const GENERIC = 'Generic';

    /** slug => id, for every brand we know. */
    /** @var array<string, int> */
    private array $bySlug = [];

    /** normalised English name => id. */
    /** @var array<string, int> */
    private array $byName = [];

    /** @var array<string, int> */
    private array $unmatched = [];

    private ?int $genericId = null;

    public function __construct()
    {
        $rows = DB::table('catalog_brands as b')
            ->leftJoin('catalog_brand_translations as t', function (JoinClause $join): void {
                $join->on('t.brand_id', '=', 'b.id')->where('t.locale', '=', 'en');
            })
            ->get(['b.id', 'b.slug', 't.name']);

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $slug = Row::nstr($row, 'slug') ?? '';
            $name = Row::nstr($row, 'name') ?? '';

            if ($slug !== '') {
                $this->bySlug[$slug] = $id;
            }
            if ($name !== '') {
                $this->byName[self::normalise($name)] = $id;
                if (mb_strtolower($name) === mb_strtolower(self::GENERIC)) {
                    $this->genericId = $id;
                }
            }
        }
    }

    /**
     * The brand id for a product title, and whether it was really found.
     *
     * @return array{id: int, matched: bool, name: string}
     */
    public function forTitle(string $title): array
    {
        $candidate = self::head($title);

        // Longest-first: "Emporio Armani" must win over "Armani Exchange"'s first word, and
        // "Grand Seiko" over "Seiko".
        $words = preg_split('/\s+/u', $candidate) ?: [];
        for ($take = min(4, count($words)); $take >= 1; $take--) {
            $probe = self::normalise(implode(' ', array_slice($words, 0, $take)));
            if ($probe === '') {
                continue;
            }
            $found = $this->byName[$probe] ?? $this->bySlug[$probe] ?? null;
            if ($found !== null) {
                /*
                 * Matching `Generic` is NOT a match. Once the first run has created that brand it
                 * is a row in the table like any other, and a title beginning with the word
                 * "Generic" — 882 of them in this file — would otherwise come back `matched: true`
                 * and lose its missing-brand marker. The product would look identified and be
                 * exactly as anonymous as before.
                 */
                if ($found === $this->genericId) {
                    break;
                }

                return ['id' => $found, 'matched' => true, 'name' => implode(' ', array_slice($words, 0, $take))];
            }
        }

        $first = $words[0] ?? '';
        if ($first !== '' && mb_strtolower($first) !== 'generic') {
            // Two words, because "Mini Focus" is more useful in the report than "Mini".
            $label = trim(implode(' ', array_slice($words, 0, 2)));
            $this->unmatched[$label] = ($this->unmatched[$label] ?? 0) + 1;
        }

        return ['id' => $this->generic(), 'matched' => false, 'name' => self::GENERIC];
    }

    /**
     * The brand with THIS name, created if we do not have it.
     *
     * For a source that names its own brand — a supplier price list, where every row is that
     * supplier's. Unlike {@see self::forTitle()} this never falls back to Generic and never marks
     * the product: the answer is not a guess, so there is nothing for a person to review.
     *
     * @return array{id: int, matched: bool, name: string}
     */
    public function named(string $name): array
    {
        $probe = self::normalise($name);
        $existing = $this->byName[$probe] ?? $this->bySlug[$probe] ?? null;
        if ($existing !== null) {
            return ['id' => $existing, 'matched' => true, 'name' => $name];
        }

        PreSwitch::assertMayCreate('lookup');

        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '', '-');
        $id = (int) DB::table('catalog_brands')->insertGetId([
            'slug' => $slug === '' ? 'brand-'.uniqid() : mb_substr($slug, 0, 120),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            DB::table('catalog_brand_translations')->insert([
                'brand_id' => $id,
                'locale' => $locale,
                'name' => $name,
            ]);
        }

        $this->byName[$probe] = $id;

        return ['id' => $id, 'matched' => true, 'name' => $name];
    }

    /**
     * The `Generic` brand, created on first use.
     *
     * Creating a brand is a `lookup` creation and is blocked before the write-switch, so this runs
     * inside the importer's declared exemption ({@see PreSwitch::allowing()}) — it does not open
     * any door of its own.
     */
    public function generic(): int
    {
        if ($this->genericId !== null) {
            return $this->genericId;
        }

        PreSwitch::assertMayCreate('lookup');

        $id = (int) DB::table('catalog_brands')->insertGetId([
            'slug' => 'generic',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['ar', 'غير محدد'], ['en', self::GENERIC]] as [$locale, $name]) {
            DB::table('catalog_brand_translations')->insert([
                'brand_id' => $id,
                'locale' => $locale,
                'name' => $name,
            ]);
        }

        $this->genericId = $id;
        $this->byName[self::normalise(self::GENERIC)] = $id;

        return $id;
    }

    /**
     * Names that matched nothing, most frequent first — the "brands you should add" list.
     *
     * @return array<string, int>
     */
    public function unmatched(): array
    {
        arsort($this->unmatched);

        return $this->unmatched;
    }

    /**
     * The part of a title that can contain the brand.
     *
     * Everything from "inspired by" onwards is cut: that phrase introduces the brand the product
     * IMITATES, and it is the difference between a Generic sunglass and a Gucci one in our own
     * catalogue.
     */
    private static function head(string $title): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $lower = mb_strtolower($clean);

        foreach ([' inspired by ', ' inspired By ', ' copy of ', ' style of '] as $cut) {
            $at = mb_strpos($lower, mb_strtolower($cut));
            if ($at !== false) {
                $clean = mb_substr($clean, 0, $at);
                break;
            }
        }

        return $clean;
    }

    /** Casing, punctuation and spacing do not make two brands different. */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['&', '’', "'"], ['and', '', ''], $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
