<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\BlogWriter;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * blogs:seed-drafts — load the articles in `database/data/articles/*.json` as DRAFTS on one
 * storefront (2026-10-01). Written for Watchizer: guides to what Egyptian shoppers search before
 * buying a watch (automatic vs quartz, sizing, water resistance, straps), in Arabic and English,
 * about watches — never about our stock or prices.
 *
 * Drafts on purpose: the team reads them, adds a cover image if they like, and publishes from the
 * dashboard's Articles screen. An article whose slug already exists is skipped, so running this
 * twice changes nothing.
 *
 *   php artisan blogs:seed-drafts                         # dry run: what would be added
 *   php artisan blogs:seed-drafts --storefront=watchizer --apply
 */
final class BlogsSeedDraftsCommand extends Command
{
    protected $signature = 'blogs:seed-drafts
        {--storefront=watchizer : the storefront code the articles belong to}
        {--apply : add them; without it nothing is written}';

    protected $description = 'Load the prepared articles as drafts on a storefront (skips existing slugs)';

    public function handle(BlogWriter $writer): int
    {
        $storefront = DB::table('storefronts')->where('code', $this->option('storefront'))->value('id');
        if (! is_numeric($storefront)) {
            $this->error('No storefront with that code.');

            return self::FAILURE;
        }
        $files = glob(database_path('data/articles/*.json')) ?: [];
        sort($files);
        $apply = (bool) $this->option('apply');
        $added = 0;
        foreach ($files as $file) {
            $article = Coerce::arr(json_decode((string) file_get_contents($file), true));
            $slug = Coerce::str($article['slug'] ?? null);
            if ($slug === '' || DB::table('core_blogs')->where('slug', $slug)->exists()) {
                $this->line("skip   {$slug} (already there)");

                continue;
            }
            $this->line(($apply ? 'add    ' : 'would add ').$slug);
            if (! $apply) {
                continue;
            }
            $id = $writer->save(null, [
                'storefront_id' => (int) $storefront,
                'slug' => $slug,
                'is_published' => false,
                'title' => Coerce::arr($article['title'] ?? null),
                'body' => Coerce::arr($article['body'] ?? null),
                'meta_title' => Coerce::arr($article['meta_title'] ?? null),
                'meta_description' => Coerce::arr($article['meta_description'] ?? null),
            ]);
            ActivityLog::record('core_blogs', $id, ActivityLog::CREATED, [], ['slug' => $slug, 'storefront_id' => (int) $storefront, 'published_at' => null], 'draft loaded: '.$slug);
            $added++;
        }
        $this->info($apply ? "Added {$added} draft(s). Publish them from the dashboard's Articles screen." : 'Nothing written. Run again with --apply.');

        return self::SUCCESS;
    }
}
