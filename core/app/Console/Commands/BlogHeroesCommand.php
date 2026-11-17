<?php

namespace App\Console\Commands;

use App\Compat\CompatLocaleSitemap;
use App\Domain\Content\BlogHero;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * EVERY ARTICLE'S HERO PICTURE (Michael, 2026-11-17): `blog:heroes [--shop=] [--apply] [--force] [--undo]`.
 * WRITES core_blogs (cover_path, hero_image_id, hero_source, hero_picked_at) and core_blog_translations.cover_alt, and
 * only with --apply; without it, a DRY RUN that lists what it would choose.
 *
 * For every article that is published, scheduled, checked or a draft, on one shop or both: when it has no hero, its
 * picture is gone, or its automatic hero's product has since been kept from search, the rule of {@see BlogHero} picks
 * one — and STORES it, so it never changes on a rebuild. The team's choice (hero_source = dashboard) is never replaced.
 * --force re-picks every automatic hero (the deploy's first run: every cover the article files named is replaced by
 * the rule's); the team's choices are still kept.
 *
 * BACKUP first (--apply): each changed article's cover_path, hero columns and both alt texts, into bk_rel_20261118_heroes,
 * once per article — a later run never overwrites it. --undo puts every backed-up article back as it was. A second run
 * with the same data changes nothing (the stored hero is valid). Scheduled daily (routes/console.php), without --force.
 */
final class BlogHeroesCommand extends Command
{
    public const BACKUP = 'bk_rel_20261118_heroes';

    protected $signature = 'blog:heroes {--shop= : a storefront code (default: every shop)} {--apply : write (default: a dry run)} {--force : re-pick every automatic hero} {--undo : restore every article backed up by --apply}';

    protected $description = "Pick and store each article's hero picture from the shop's own in-stock products (dry run unless --apply)";

    public function handle(BlogHero $hero): int
    {
        if ((bool) $this->option('undo')) {
            return $this->undo();
        }
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $q = DB::table('core_blogs')->whereIn('status', ['published', 'scheduled', 'checked', 'draft', 'held'])->orderBy('storefront_id')->orderBy('id');
        $shop = Coerce::nstr($this->option('shop'));
        if ($shop !== null && $shop !== '') {
            $sf = Coerce::int(DB::table('storefronts')->where('code', $shop)->value('id'));
            if ($sf === 0) {
                $this->error('No such shop: '.$shop);

                return self::FAILURE;
            }
            $q->where('storefront_id', $sf);
        }
        if ($apply) {
            $this->ensureBackupTable();
        }
        $changed = 0;
        $none = 0;
        $kept = 0;
        $shops = [];
        foreach ($q->pluck('id') as $rawId) {
            $id = Coerce::int($rawId);
            $blog = Row::cast(DB::table('core_blogs')->where('id', $id)->first(BlogHero::COLUMNS) ?? new \stdClass);
            $before = $blog;
            $reason = $hero->reason($blog);
            // the rule's answer, computed without writing; written below, after the backup
            $pick = $hero->ensure($id, $force, false);
            if ($pick === null) {
                if ($reason !== '' && $hero->choose($blog) === null) {
                    $none++;
                    $this->line(sprintf('  NONE  #%d %s - %s, and the shop has no product to show', $id, Row::str($before, 'slug'), $reason));
                } else {
                    $kept++;
                }

                continue;
            }
            if ($apply) {
                $this->backup($id);
                $hero->save($id, $pick, BlogHero::AUTO);
            }
            $changed++;
            $shops[Row::int($before, 'storefront_id')] = true;
            $this->line(sprintf('  %s #%d %s <- image %d (product %d, %s) - %s', $apply ? 'SET ' : 'WOULD', $id, Row::str($before, 'slug'), $pick['image_id'], $pick['product_id'], $pick['title'], $pick['reason']));
        }
        $this->info(sprintf('%s: %d %s, %d kept as they are, %d without a product to show.', $apply ? 'APPLIED' : 'DRY RUN', $changed, $apply ? 'set' : 'would be set', $kept, $none));
        if ($apply && $changed > 0) {
            foreach (array_keys($shops) as $sf) {
                self::refreshSitemaps($sf);
            }
        }

        return self::SUCCESS;
    }

    /**
     * The articles' pictures are in the shop's sitemaps (image:image). Their cached urlsets are FORGOTTEN here — the last
     * good copies stay and are served — and catalog:warm (every five minutes, and the deploy's own step) builds the
     * new ones. Not built in this process: a full rebuild needs more memory than a CLI's default 128 MB (measured
     * 2026-11-17 on a production copy).
     */
    private static function refreshSitemaps(int $sf): void
    {
        CompatLocaleSitemap::forget($sf);
    }

    private function ensureBackupTable(): void
    {
        if (DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            return;
        }
        DB::statement(self::backupDdl());
    }

    /** The backup table's CREATE, public so a test can make it on another connection first (Tests\Support\SideDdl). */
    public static function backupDdl(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::BACKUP.' (blog_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, cover_path VARCHAR(255) NULL, hero_image_id BIGINT UNSIGNED NULL, hero_source VARCHAR(16) NULL, hero_picked_at TIMESTAMP NULL, alt_en VARCHAR(255) NULL, alt_ar VARCHAR(255) NULL, created_at TIMESTAMP NULL)';
    }

    /** The article as it is now, once (INSERT IGNORE: a later run never overwrites the first backup). */
    private function backup(int $id): void
    {
        DB::statement('INSERT IGNORE INTO '.self::BACKUP.' (blog_id, cover_path, hero_image_id, hero_source, hero_picked_at, alt_en, alt_ar, created_at)
            SELECT b.id, b.cover_path, b.hero_image_id, b.hero_source, b.hero_picked_at,
              (SELECT t.cover_alt FROM core_blog_translations t WHERE t.blog_id = b.id AND t.locale = \'en\' LIMIT 1),
              (SELECT t.cover_alt FROM core_blog_translations t WHERE t.blog_id = b.id AND t.locale = \'ar\' LIMIT 1), NOW()
            FROM core_blogs b WHERE b.id = ?', [$id]);
    }

    private function undo(): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->error('No backup table '.self::BACKUP.' - nothing to undo.');

            return self::FAILURE;
        }
        $n = 0;
        $shops = [];
        foreach (DB::table(self::BACKUP)->get() as $raw) {
            $r = Row::cast($raw);
            $id = Row::int($r, 'blog_id');
            DB::transaction(function () use ($r, $id): void {
                DB::table('core_blogs')->where('id', $id)->update([
                    'cover_path' => Row::nstr($r, 'cover_path'),
                    'hero_image_id' => Row::nint($r, 'hero_image_id'),
                    'hero_source' => Row::nstr($r, 'hero_source'),
                    'hero_picked_at' => Row::nstr($r, 'hero_picked_at'),
                ]);
                DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', 'en')->update(['cover_alt' => Row::nstr($r, 'alt_en')]);
                DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', 'ar')->update(['cover_alt' => Row::nstr($r, 'alt_ar')]);
            });
            $sf = Coerce::int(DB::table('core_blogs')->where('id', $id)->value('storefront_id'));
            $shops[$sf] = true;
            $n++;
        }
        foreach (array_keys($shops) as $sf) {
            self::refreshSitemaps($sf);
        }
        $this->info("UNDONE: {$n} articles put back as they were before --apply.");

        return self::SUCCESS;
    }
}
