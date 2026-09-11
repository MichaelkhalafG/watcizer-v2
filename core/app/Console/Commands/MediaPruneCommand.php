<?php

namespace App\Console\Commands;

use App\Domain\Media\MediaStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /** Set when any reference query failed: a partial reference set must never authorise a delete. */
    private bool $scanFailed = false;

    public function handle(): int
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
        $referenced = $this->referencedFiles();
        if (! $json) {
            $this->line('reference scan: '.count($referenced).' distinct filename(s) referenced by clean + legacy rows');
        }

        $report = [];
        $orphans = [];
        $matched = 0;
        foreach ($types as $type) {
            $folder = MediaStore::typeConfig($type)['folder'];
            try {
                $directory = MediaStore::directory($folder);
            } catch (Throwable $e) {
                // A folder we could not read is a HOLE in the report, so the run is not a
                // success even if everything else worked (review 🟡-5).
                $this->error("skipping {$type}: ".$e->getMessage());
                $this->scanFailed = true;

                continue;
            }

            $groups = $this->groupByMaster($directory);
            $kept = 0;
            $young = 0;
            foreach ($groups as $master => $files) {
                if (isset($referenced[$master])) {
                    $kept++;

                    continue;
                }
                $newest = 0;
                $bytes = 0;
                foreach ($files as $file) {
                    $newest = max($newest, (int) (@filemtime($file) ?: 0));
                    $bytes += (int) (@filesize($file) ?: 0);
                }
                if ($minAge > 0 && $newest > time() - ($minAge * 86400)) {
                    $young++;

                    continue;
                }
                $orphans[] = ['type' => $type, 'folder' => $folder, 'master' => $master, 'files' => $files, 'bytes' => $bytes];
            }

            $matched += $kept;
            $report[] = [$type, $folder, count($groups), $kept, $young, count(array_filter($orphans, fn (array $o): bool => $o['type'] === $type))];
        }

        $bytes = array_sum(array_map(fn (array $o): int => $o['bytes'], $orphans));
        $fileCount = array_sum(array_map(fn (array $o): int => count($o['files']), $orphans));

        if ($json) {
            // ONLY the payload on stdout in this mode: a caller pipes it into a parser, and a
            // stray human line makes the whole document invalid.
            $this->line((string) json_encode([
                'dry_run' => ! $delete,
                'referenced' => count($referenced),
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
            return $this->scanFailed ? self::FAILURE : self::SUCCESS;
        }

        // Guard 5a: ANY unreadable reference source blocks deletion outright. Counting is not
        // enough — the dry run proved it: one broken connection name still left 2 323 legacy
        // references, so a count-only floor would have waved a half-blind delete through.
        if ($this->scanFailed) {
            $this->error('REFUSING to delete: at least one reference source could not be read (see the warnings above).');
            $this->line('  A partial reference set cannot authorise a deletion. Fix the scan, then re-run.');

            return self::FAILURE;
        }

        // Guard 5c: does this tree even LOOK like the tree the database describes? On the
        // developer's workstation the answer is no — `Uploads_Images` there is a partial copy, and
        // this command found 74 files, NONE of them referenced, and none of the 2 323 referenced
        // files present. A `--delete` on that machine would have wiped the whole local tree while
        // reporting itself perfectly correct. If not one referenced file is on disk, the tree and
        // the database are not describing the same thing.
        if ($matched === 0 && $referenced !== []) {
            $this->error('REFUSING to delete: not ONE of the '.count($referenced).' referenced files exists in this tree.');
            $this->line('  The directory and the database are describing different worlds — a partial copy, a wrong');
            $this->line('  MEDIA_ROOT, or an unmounted share. Everything here would look like an orphan.');

            return self::FAILURE;
        }
        if ($matched > 0 && $matched * 10 < count($referenced)) {
            $this->warn(sprintf(
                'only %d of %d referenced files are present in this tree (%.1f%%) — check MEDIA_ROOT before trusting the orphan list.',
                $matched, count($referenced), 100 * $matched / max(1, count($referenced)),
            ));
        }

        // Guard 5b: a scan that found almost nothing is a broken scan, not an empty shop.
        if (count($referenced) < $minReferences) {
            $this->error(sprintf(
                'REFUSING to delete: the reference scan found only %d referenced filename(s), below the --min-references floor of %d.',
                count($referenced), $minReferences,
            ));
            $this->line('  That pattern means the scan failed (a renamed column, a table missing mid-rebuild), not that the tree is unused.');

            return self::FAILURE;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($orphans as $orphan) {
            foreach ($orphan['files'] as $file) {
                @unlink($file) ? $deleted++ : $failed++;
            }
        }

        $this->newLine();
        $this->info("deleted {$deleted} file(s), ".self::humanBytes($bytes).' reclaimed.');
        if ($failed > 0) {
            $this->warn("{$failed} file(s) could not be deleted (permissions?).");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every filename any row references, from BOTH connections.
     *
     * Keyed by filename for O(1) lookup. A stored value may be a bare filename (the convention,
     * §5.4) or carry a path — legacy rows are not perfectly clean — so only the basename is
     * compared, which is also what makes a legacy `Product/x.webp` and a clean `x.webp` match.
     *
     * @return array<string, true>
     */
    public function referencedFiles(): array
    {
        $referenced = [];
        foreach (self::REFERENCE_COLUMNS as $schema => $tables) {
            // 'clean' → the default connection (named `mariadb`); 'legacy' → the legacy one.
            $connection = $schema === 'legacy' ? 'legacy' : null;
            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    try {
                        $values = DB::connection($connection)->table($table)->whereNotNull($column)->distinct()->pluck($column);
                    } catch (Throwable $e) {
                        // A missing table mid-rebuild must not silently shrink the reference set:
                        // say so, and let the --min-references floor catch the consequence.
                        $this->warn("reference scan: cannot read {$schema}.{$table}.{$column} — ".$e->getMessage());
                        $this->scanFailed = true;

                        continue;
                    }
                    foreach ($values as $value) {
                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }
                        $referenced[basename(trim($value))] = true;
                    }
                }
            }
        }

        // …and anything embedded in long text, which a column scan cannot see.
        foreach (self::INLINE_TEXT_COLUMNS as $schema => $tables) {
            $connection = $schema === 'legacy' ? 'legacy' : null;
            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    try {
                        $rows = DB::connection($connection)->table($table)->where($column, 'like', '%Uploads_Images%')->pluck($column);
                    } catch (Throwable $e) {
                        $this->warn("inline scan: cannot read {$schema}.{$table}.{$column} — ".$e->getMessage());
                        $this->scanFailed = true;

                        continue;
                    }
                    foreach ($rows as $value) {
                        if (! is_string($value)) {
                            continue;
                        }
                        // Any `…/Uploads_Images/<folder>/<file>` occurrence, however it is quoted.
                        if (preg_match_all('#Uploads_Images/[^"\'\\s>)]+/([^"\'\\s>)]+)#i', $value, $matches) > 0) {
                            foreach ($matches[1] as $file) {
                                $referenced[basename($file)] = true;
                            }
                        }
                    }
                }
            }
        }

        return $referenced;
    }

    /**
     * Group a directory's files by their MASTER filename.
     *
     * `1700_2026-01-01_abc.webp` is a master; `1700_2026-01-01_abc-320.avif` is its rendition. No
     * row ever references a rendition, so judging one on its own would delete every rendition in
     * the tree.
     *
     * @return array<string, list<string>> master filename => absolute paths (master + renditions)
     */
    private function groupByMaster(string $directory): array
    {
        $groups = [];
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $name = basename($path);
            // `<base>-<width>.<ext>` → `<base>.webp`
            if (preg_match('/^(.+)-(\d{2,4})\.(avif|webp)$/', $name, $matches) === 1) {
                $master = $matches[1].'.webp';
                $groups[$master][] = $path;

                continue;
            }
            $groups[$name][] = $path;
        }

        /** @var array<string, list<string>> $groups */
        return $groups;
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
