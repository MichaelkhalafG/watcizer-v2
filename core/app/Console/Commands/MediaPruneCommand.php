<?php

namespace App\Console\Commands;

use App\Domain\Media\MediaAudit;
use App\Domain\Media\MediaStore;
use Illuminate\Console\Command;

/**
 * media:prune — find image files in the shared tree that NOTHING references, and (only when told)
 * delete them.
 *
 * The dashboard's upload endpoint stores a file immediately and the owning screen attaches it
 * afterwards, so an abandoned form leaves an orphan (study §3.11.7). This is the broom for that.
 * It is also the most dangerous command in the repo, because it deletes customer-visible assets
 * from a directory the LIVE legacy application serves — so every safety property below is
 * deliberate and tested.
 *
 * ── Safety ───────────────────────────────────────────────────────────────────────────────────
 *
 *  1. **Dry-run by DEFAULT.** It prints what it would delete and exits 0 — or NON-ZERO if any
 *     part of the scan failed, so "nothing to prune" is never confused with "I could not look".
 *     Deleting requires `--delete`, and every refusal below also exits non-zero.
 *  2. **BOTH schemas are scanned.** The tree is shared with the legacy app during the transition
 *     (§1, §5.4), so a file can be referenced by a CLEAN row, a LEGACY row, or both. Scanning only
 *     the clean side would delete every image the legacy storefront is still serving — the single
 *     worst mistake this command could make.
 *  3. **A rendition belongs to its master.** `x-320.avif` is kept or deleted with `x.webp`;
 *     renditions are never judged on their own, because no row ever references one.
 *  4. **An age floor.** A file younger than `--min-age-days` (default 7) is never deleted, so an
 *     upload that is mid-form when the command runs is safe.
 *  5. **A sanity floor.** If the reference scan finds implausibly few references
 *     (`< --min-references`, default 100) the command REFUSES to delete anything: that pattern
 *     means the scan failed — a renamed column, a dropped table mid-rebuild — not that the shop
 *     has no images.
 *  6. **A coverage floor.** If NOT ONE referenced file is present in the tree, the directory and
 *     the database are describing different worlds (a partial copy, a wrong `MEDIA_ROOT`, an
 *     unmounted share) and everything looks like an orphan — so it refuses. This is not
 *     hypothetical: on the developer's workstation the tree holds 74 files, none referenced, while
 *     none of the 2 323 referenced files are present at all.
 *  7. **It only ever looks at the folders `config/media.php` declares**, so nothing outside the
 *     known media tree is in scope.
 *
 *   php artisan media:prune                       # report only
 *   php artisan media:prune --delete              # act, with every guard above
 *   php artisan media:prune --type=product --json
 */
final class MediaPruneCommand extends Command
{
    protected $signature = 'media:prune
        {--delete : actually delete (default is a dry run that changes nothing)}
        {--type=* : limit to these media types (default: every type in config/media.php)}
        {--min-age-days=7 : never delete a file younger than this}
        {--min-references=100 : refuse to delete if the reference scan found fewer than this many files}
        {--json}';

    protected $description = 'Report (and optionally delete) media files that no clean or legacy row references';

    /**
     * Every column that can hold a media filename, per connection.
     *
     * Written out rather than discovered, for the same reason the transform uses explicit column
     * lists (AGENTS §9): a column this list forgets becomes a file this command deletes. Adding a
     * media column anywhere means adding it here, and `MediaPruneTest` fails if a table named here
     * stops existing.
     *
     * The outer key is a connection: `null` means the default (clean) connection, which is NAMED
     * `mariadb` — `DB::connection('default')` throws "not configured", and the dry run caught
     * exactly that: the entire clean-side scan failed silently while the legacy side still
     * returned 2 323 references, comfortably above the sanity floor. A `--delete` would have run
     * with half the references missing. Hence `null`, and hence the floor is not the only guard.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const REFERENCE_COLUMNS = [
        'clean' => [
            'catalog_product_images' => ['path'],
            'catalog_brands' => ['logo_path'],
            'catalog_grades' => ['image_path'],
            'storefront_banners' => ['image_path'],
            // Found by the DERIVED coverage guard (review 🟠-3), not by reading this list: category
            // images were invisible to the prune, so every one of them was an "orphan".
            'storefront_categories' => ['image_path'],
            /*
             * Article covers (item 14, 2026-09-18) — and found the same way, by the same guard,
             * within minutes of the table existing. Without this line `media:prune --delete` would
             * have treated every blog cover as an orphan and deleted it, and the articles screen
             * would have started showing broken images with nothing to explain why.
             *
             * That is twice now that this list was forgotten and twice that the coverage test
             * caught it. It is doing more work than the list it guards.
             */
            'core_blogs' => ['cover_path'],
        ],
        'legacy' => [
            'products' => ['image'],
            'product_images' => ['image'],
            'product_variants' => ['image'],
            'brands' => ['image'],
            'grades' => ['image'],
            'categories' => ['image'],
            'category_types' => ['image'],
            'sub_types' => ['image'],
            'offers' => ['image'],
            'blogs' => ['image'],
            'blog_images' => ['image'],
            'banner_homes' => ['image'],
            'banner_sides' => ['image'],
            'banner_bottoms' => ['image'],
            'users' => ['image'],
        ],
    ];

    /**
     * Long-text columns that may EMBED an image reference (a `<img src="…/Uploads_Images/…">`
     * pasted into a description by the content team).
     *
     * A column-only scan would miss those and delete a file a live page still shows. Nothing in
     * the current data does this (`… LIKE '%Uploads_Images%'` returns 0 rows today), which is
     * precisely why it is cheap to cover now rather than after someone pastes one in.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const INLINE_TEXT_COLUMNS = [
        'clean' => [
            'catalog_product_translations' => ['long_description', 'short_description'],
        ],
        'legacy' => [
            'product_translations' => ['long_description', 'short_description'],
            'offer_translations' => ['long_description', 'short_description'],
            'blog_translations' => ['text'],
        ],
    ];

    public function handle(MediaAudit $audit): int
    {
        $delete = (bool) $this->option('delete');
        $minAge = max(0, self::intOption($this->option('min-age-days')));
        $minReferences = max(0, self::intOption($this->option('min-references')));
        $types = $this->types();
        if ($types === []) {
            $this->error('No known media type selected. Types: '.implode(', ', MediaStore::types()));

            return self::INVALID;
        }

        $json = (bool) $this->option('json');

        /*
         * The scan, the refusals and the delete all live in `MediaAudit` (wave 4D, task C4) so that
         * the dashboard screen asks the SAME questions with the SAME guards. This command is now
         * the way an operator runs it from a terminal, and the screen is the way they see it — but
         * there is one implementation of "what is an orphan" and one of "when may I delete", which
         * is the only arrangement that cannot drift.
         */
        $scan = $audit->scan($types, $minAge);
        foreach ($scan['warnings'] as $warning) {
            $this->warn($warning);
        }

        $orphans = $scan['orphans'];
        $bytes = $scan['bytes'];
        $fileCount = $scan['files'];

        if (! $json) {
            $this->line('reference scan: '.$scan['referenced'].' distinct filename(s) referenced by clean + legacy rows');
        }

        $report = [];
        foreach ($scan['types'] as $row) {
            $report[] = [$row['type'], $row['folder'], $row['masters'], $row['referenced'], $row['young'], $row['orphans']];
        }

        if ($json) {
            // ONLY the payload on stdout in this mode: a caller pipes it into a parser, and a
            // stray human line makes the whole document invalid.
            $this->line((string) json_encode([
                'dry_run' => ! $delete,
                'referenced' => $scan['referenced'],
                'orphan_masters' => count($orphans),
                'orphan_files' => $fileCount,
                'bytes' => $bytes,
                'orphans' => array_map(fn (array $o): array => ['type' => $o['type'], 'master' => $o['master'], 'files' => count($o['files'])], $orphans),
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->table(['type', 'folder', 'masters on disk', 'referenced', 'too young', 'ORPHAN'], $report);
            $this->line(sprintf(
                '%d orphan master(s), %d file(s) including renditions, %s',
                count($orphans), $fileCount, self::humanBytes($bytes),
            ));
            foreach (array_slice($orphans, 0, 20) as $orphan) {
                $this->line('  · '.$orphan['folder'].'/'.$orphan['master'].' (+'.(count($orphan['files']) - 1).' rendition(s))');
            }
            if (count($orphans) > 20) {
                $this->line('  … '.(count($orphans) - 20).' more');
            }
        }

        if (! $delete) {
            if (! $json) {
                $this->newLine();
                $this->info('DRY RUN — nothing was deleted. Re-run with --delete to remove the files above.');
            }

            // Even a dry run reports failure when it could not see everything: a caller (or a cron)
            // must be able to tell "nothing to prune" from "I could not look".
            return $scan['scan_failed'] ? self::FAILURE : self::SUCCESS;
        }

        $refusal = MediaAudit::refusal($scan, $minReferences);
        if ($refusal !== null) {
            // English in the terminal, Arabic on the screen — one decision, two renderings.
            $this->error('REFUSING to delete: '.$refusal['en']);

            return self::FAILURE;
        }

        $coverage = MediaAudit::coverageWarning($scan);
        if ($coverage !== null) {
            $this->warn($coverage);
        }

        $result = $audit->delete($orphans);

        $this->newLine();
        $this->info("deleted {$result['deleted']} file(s), ".self::humanBytes($bytes).' reclaimed.');
        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} file(s) could not be deleted (permissions?).");
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function types(): array
    {
        // `--type=*` always hands back an array (possibly empty), so no is_array() dance.
        $requested = [];
        foreach ($this->option('type') as $type) {
            if (is_string($type) && $type !== '') {
                $requested[] = $type;
            }
        }

        if ($requested === []) {
            return MediaStore::types();
        }

        return array_values(array_intersect($requested, MediaStore::types()));
    }

    private static function intOption(mixed $value): int
    {
        return (int) (is_numeric($value) ? $value : 0);
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 1).' KB';
    }
}
