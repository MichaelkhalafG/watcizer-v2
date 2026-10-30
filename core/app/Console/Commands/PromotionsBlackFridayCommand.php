<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Promotions\PromotionRules;
use App\Domain\Promotions\PromotionWriter;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Black Friday coupon, Black1000, as DATA (M1u, 2026-10-30, Michael): EGP 1,000 off EACH eligible watch of six
 * brands, on Watchizer, applying to items already on sale, created INACTIVE with no dates — Michael switches it on
 * and sets the window on the dashboard's Promotions screen.
 *
 * Without `--apply` it writes nothing and prints what it would do. Idempotent: a rule that already carries the code
 * is reported and left alone (edit it on the dashboard). The brands are matched by their records — slug, else the
 * English name — and a brand that is not found is REPORTED and never guessed; the rule is then not created unless
 * `--allow-missing` says to go ahead with the ones found.
 *
 * It goes through `PromotionWriter`, the dashboard's own path, so every refusal the screen gives applies here too —
 * including the one that matters: a money reward is refused on a storefront whose money rewards are switched off
 * (Storefronts → Watchizer → "Money rewards"). Switch that on only after the storefronts that show the discount
 * are deployed (runbook PROMOTIONS_20261030 §5).
 */
final class PromotionsBlackFridayCommand extends Command
{
    public const CODE = 'Black1000';

    /** The six brands, by the slug the catalogue uses, with the English name as the fallback match. */
    public const BRANDS = [
        'rolex' => 'Rolex',
        'patek-philippe' => 'Patek Philippe',
        'audemars-piguet' => 'Audemars Piguet',
        'omega' => 'Omega',
        'tissot' => 'Tissot',
        'hublot' => 'Hublot',
    ];

    protected $signature = 'promotions:black-friday
        {--storefront=watchizer : the storefront code the coupon runs on}
        {--floor=0 : the lowest a discounted watch may cost, in EGP}
        {--allow-missing : create it with the brands found even when one is missing}
        {--apply : create it; without it nothing is written}';

    protected $description = 'Create the Black Friday coupon Black1000 (EGP 1,000 off each eligible watch), inactive, without dates';

    public function handle(PromotionWriter $writer): int
    {
        $storefrontId = Coerce::nint(DB::table('storefronts')->where('code', $this->option('storefront'))->value('id'));
        if ($storefrontId === null) {
            $this->error('No storefront with that code.');

            return self::FAILURE;
        }

        $existing = DB::table('promotion_rules')->where('code', self::CODE)->first(['id', 'name', 'is_active']);
        if (is_object($existing)) {
            $this->info('Already there: rule #'.Coerce::int($existing->id ?? null).' «'.Coerce::str($existing->name ?? '').'» ('
                .(Coerce::bool($existing->is_active ?? null) ? 'active' : 'inactive').'). Nothing written — edit it on the dashboard.');

            return self::SUCCESS;
        }

        $found = [];
        $missing = [];
        foreach (self::BRANDS as $slug => $name) {
            $id = Coerce::nint(DB::table('catalog_brands')->where('slug', $slug)->value('id'))
                ?? Coerce::nint(DB::table('catalog_brand_translations')->where('locale', 'en')->where('name', $name)->value('brand_id'));
            if ($id === null) {
                $missing[] = $name;
                $this->warn("NOT FOUND  {$name} (slug {$slug})");

                continue;
            }
            $watches = DB::table('catalog_products')->where('brand_id', $id)->where('family', 'watch')->whereNull('deleted_at')->count();
            $this->line(sprintf('found      #%-4d %-16s %d watch(es)', $id, $name, $watches));
            $found[] = $id;
        }
        if ($found === [] || ($missing !== [] && ! $this->option('allow-missing'))) {
            $this->error($missing === [] ? 'No brand found.' : 'Missing: '.implode(', ', $missing).'. Nothing written (use --allow-missing to go ahead with the rest).');

            return self::FAILURE;
        }

        $floor = max(0.0, (float) Coerce::str($this->option('floor') ?? '0'));
        if (! PromotionRules::moneyRewardsEnabled($storefrontId)) {
            $this->warn('Money rewards are OFF on this storefront: the dashboard (and this command) refuse a money coupon there. '
                .'Switch them on (Storefronts → the storefront → Money rewards) once the storefronts showing the discount are deployed, then run again.');
        }

        $data = [
            'name' => 'Black Friday — Black1000',
            'code' => self::CODE,
            'label_en' => 'Black Friday: EGP 1,000 off each watch',
            'label_ar' => 'الجمعة البيضاء: خصم ١٬٠٠٠ جنيه على كل ساعة', // i18n-exempt: DATA — the rule's own Arabic label, stored on the row and shown to shoppers, editable on the dashboard
            'priority' => 0,
            'is_active' => false,                       // Michael switches it on
            'starts_at' => null,                        // …and sets the window, in Cairo time
            'ends_at' => null,
            'apply_per' => PromotionMoney::PER_ITEM,    // two eligible watches = EGP 2,000
            'include_sale_items' => true,
            'stackable' => false,
            'unit_floor' => $floor,
            'storefronts' => [$storefrontId],
            'conditions' => [],
            'rewards' => [['type' => 'fixed_discount', 'amount' => 1000]],
            'scopes' => array_merge(
                array_map(fn (int $id): array => ['role' => 'target', 'dimension' => 'brand', 'value_id' => $id], $found),
                [['role' => 'target', 'dimension' => 'family', 'value' => 'watch']],
            ),
        ];

        $this->line('');
        $this->line('Rule: code '.self::CODE.', EGP 1,000 off EACH eligible watch, on sale included, floor EGP '.number_format($floor, 2)
            .', no stacking, INACTIVE, no dates, storefront #'.$storefrontId.'.');
        if (! $this->option('apply')) {
            $this->info('Nothing written. Run again with --apply.');

            return self::SUCCESS;
        }

        try {
            $id = $writer->save($data, null, null);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', array_map(fn (mixed $m): string => Coerce::str($m), Coerce::arr($messages))));
            }

            return self::FAILURE;
        }
        ActivityLog::record('promotion_rules', $id, ActivityLog::CREATED, [], ['code' => self::CODE, 'is_active' => false],
            'created by promotions:black-friday');
        $this->info("Created rule #{$id} (inactive). Set its dates and switch it on from the dashboard's Promotions screen.");

        return self::SUCCESS;
    }
}
