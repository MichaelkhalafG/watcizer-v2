<?php

use App\Transform\Row;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A META TITLE WRITTEN BY HAND (SEO Phase 1, Michael 2026-11-14, decision D7). The product pages now build their <title>
 * from the catalogue (brand + model name + model number + gender + type); a meta title a PERSON wrote in the dashboard
 * still wins. This marks which ones those are.
 *
 * Going forward the product writer and patcher set it: on when a signed-in person changes the meta title to a text, off
 * when anything changes it otherwise (an artisan import, the clean-up command) or empties it.
 *
 * The backfill reads the activity log (it covers the dashboard since 2026-09-25): a product's LATEST logged meta-title
 * change per language, made by a named user, whose text is STILL the stored one — and which is NOT the dashboard's SEO
 * button's output (the product's title, possibly shortened, plus at most one " | brand" or " | category"), nor Arabic
 * text in the English field. A person who pressed the button saved the generator's words, not theirs. On the
 * 2026-11-14 copy: 82 logged titles, 80 of them the button's shape, 1 Arabic-in-English, 1 left (product 26668, EN).
 * The 380 unnamed entries of 2026-09-30 are `catalog:clean-meta-titles` stripping "| Select…" (a machine). Before
 * 2026-09-25 nothing tells a hand-written title from an imported one, and the patterns (marketplace tails, cut-offs,
 * «قطعة أزياء») say import.
 *
 * Additive with a default, so the code before this change runs unchanged beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product_translations', function (Blueprint $t): void {
            $t->boolean('meta_title_by_hand')->default(false)->after('meta_title');
        });

        /** @var array<string, string|null> $latest "product:locale" => the text a person set last */
        $latest = [];
        DB::table('core_activity_log')
            ->where('subject_type', 'catalog_products')
            ->whereNotNull('user_id')
            ->where('changes', 'like', '%meta_title%')
            ->orderBy('id')
            ->select(['id', 'subject_id', 'changes'])
            ->chunkById(500, function ($rows) use (&$latest): void {
                foreach ($rows as $row) {
                    $changes = json_decode(Row::nstr($row, 'changes') ?? '', true);
                    if (! is_array($changes)) {
                        continue;
                    }
                    foreach (['en', 'ar'] as $locale) {
                        $change = $changes['meta_title_'.$locale] ?? null;
                        if (is_array($change) && array_key_exists('to', $change)) {
                            $to = is_string($change['to']) ? trim($change['to']) : null;
                            $latest[Row::int($row, 'subject_id').':'.$locale] = $to === '' ? null : $to;
                        }
                    }
                }
            });

        foreach ($latest as $key => $text) {
            if ($text === null) {
                continue;
            }
            [$productId, $locale] = explode(':', $key);
            $stored = DB::table('catalog_product_translations')
                ->where('product_id', (int) $productId)->where('locale', $locale)->value('meta_title');
            if (! is_string($stored) || trim($stored) !== $text) {
                continue;
            }
            if (($locale === 'en' && preg_match('/\p{Arabic}/u', $text) === 1) || $this->isGeneratorShape((int) $productId, $locale, $text)) {
                continue;
            }
            DB::table('catalog_product_translations')
                ->where('product_id', (int) $productId)->where('locale', $locale)
                ->update(['meta_title_by_hand' => true]);
        }
    }

    /**
     * The dashboard SEO button's title (resources/js/lib/seo.ts, metaTitle): the product's title, possibly shortened,
     * then at most one " | " segment — the brand's name or one of the product's category names, in that language.
     */
    private function isGeneratorShape(int $productId, string $locale, string $text): bool
    {
        $tidy = fn (?string $s): string => trim((string) preg_replace('/\s+/u', ' ', (string) $s));
        $raw = DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', $locale)->value('title');
        $title = $tidy(is_string($raw) ? $raw : null);
        $text = $tidy($text);
        if ($title === '') {
            return false;
        }
        $at = mb_strrpos($text, ' | ');
        $head = $at === false ? $text : mb_substr($text, 0, $at);
        $tail = $at === false ? '' : mb_substr($text, $at + 3);
        $head = rtrim($head, " .…");
        if ($head === '' || ! str_starts_with($title, $head)) {
            return false;
        }
        if ($tail === '') {
            return true;
        }
        $brand = DB::table('catalog_products as p')
            ->join('catalog_brand_translations as b', 'b.brand_id', '=', 'p.brand_id')
            ->where('p.id', $productId)->where('b.locale', $locale)->value('b.name');
        $names = DB::table('storefront_category_product as scp')
            ->join('storefront_category_translations as ct', 'ct.storefront_category_id', '=', 'scp.storefront_category_id')
            ->where('scp.product_id', $productId)->where('ct.locale', $locale)->pluck('ct.name')->all();
        foreach (array_merge([$brand], $names) as $name) {
            if (is_string($name) && $tidy($name) === $tail) {
                return true;
            }
        }
        // a category the product no longer carries: still the button's shape when the tail is any category's name
        return DB::table('storefront_category_translations')->where('locale', $locale)->where('name', $tail)->exists();
    }

    public function down(): void
    {
        Schema::table('catalog_product_translations', function (Blueprint $t): void {
            $t->dropColumn('meta_title_by_hand');
        });
    }
};
