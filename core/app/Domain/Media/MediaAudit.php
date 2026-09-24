<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Console\Commands\MediaPruneCommand;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The media orphan scan and its refusals, extracted so a SCREEN can ask the same questions as the
 * command (wave 4D, task C4).
 *
 * ── Why this is a service and not "the controller runs artisan" ──────────────────────────────
 *
 * `media:prune` is the most dangerous command in the repo: it deletes customer-visible files from a
 * directory the LIVE legacy application still serves. Its safety is not the `--delete` flag, it is
 * the five guards — both schemas scanned, renditions grouped with their master, an age floor, a
 * reference floor, a coverage floor. A screen that shelled out to the command, or worse
 * re-implemented the scan to draw a table, would be a second implementation of exactly the logic
 * that must never drift.
 *
 * So the logic lives here, once. The command prints it; the screen renders it; **both call the same
 * `refusal()` before anything is deleted**, and a test asserts they agree.
 *
 * The column lists stay on {@see MediaPruneCommand} — they are its documented contract, referenced
 * by the study and by `MediaPruneTest`, and moving them would have broken every reference to them
 * for no gain.
 */
final class MediaAudit
{
    /** @var list<string> things the scan could not read — a partial scan may never authorise a delete */
    public array $warnings = [];

    public bool $scanFailed = false;

    /**
     * Scan the tree and report what is referenced, what is too young, and what is an orphan.
     *
     * @param  list<string>  $types  media types from `config/media.php`
     * @return array{
     *     types: list<array{type: string, folder: string, masters: int, referenced: int, young: int, orphans: int, bytes: int}>,
     *     orphans: list<array{type: string, folder: string, master: string, files: list<string>, bytes: int}>,
     *     referenced: int,
     *     matched: int,
     *     bytes: int,
     *     files: int,
     *     warnings: list<string>,
     *     scan_failed: bool,
     * }
     */
    public function scan(array $types, int $minAgeDays): array
    {
        $referenced = $this->referencedFiles();

        $perType = [];
        $orphans = [];
        $matched = 0;

        foreach ($types as $type) {
            $folder = MediaStore::typeConfig($type)['folder'];

            try {
                $directory = MediaStore::directory($folder);
            } catch (Throwable $e) {
                // A folder we could not read is a HOLE in the report, so the run is not a success
                // even if everything else worked.
                $this->warnings[] = "skipping {$type}: ".$e->getMessage();
                $this->scanFailed = true;

                continue;
            }

            $groups = self::groupByMaster($directory);
            $kept = 0;
            $young = 0;
            $typeOrphans = 0;
            $typeBytes = 0;

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

                if ($minAgeDays > 0 && $newest > time() - ($minAgeDays * 86400)) {
                    $young++;

                    continue;
                }

                $orphans[] = ['type' => $type, 'folder' => $folder, 'master' => $master, 'files' => $files, 'bytes' => $bytes];
                $typeOrphans++;
                $typeBytes += $bytes;
            }

            $matched += $kept;
            $perType[] = [
                'type' => $type, 'folder' => $folder, 'masters' => count($groups),
                'referenced' => $kept, 'young' => $young, 'orphans' => $typeOrphans, 'bytes' => $typeBytes,
            ];
        }

        $bytes = 0;
        $files = 0;
        foreach ($orphans as $orphan) {
            $bytes += $orphan['bytes'];
            $files += count($orphan['files']);
        }

        return [
            'types' => $perType,
            'orphans' => $orphans,
            'referenced' => count($referenced),
            'matched' => $matched,
            'bytes' => $bytes,
            'files' => $files,
            'warnings' => $this->warnings,
            'scan_failed' => $this->scanFailed,
        ];
    }

    /**
     * Why this scan may NOT authorise a deletion — or null when it may.
     *
     * Three refusals, each earned:
     *
     *  • **a partial scan** — one unreadable reference source still left 2 323 legacy references
     *    on the first dry run, comfortably above any count floor, while the entire clean side had
     *    failed silently. A count is not a substitute for "I read everything".
     *  • **no coverage** — on the developer's workstation the tree holds 74 files, NONE referenced,
     *    and none of the 2 323 referenced files are present. Everything there looks like an orphan,
     *    and a delete would have wiped the local tree while reporting itself perfectly correct.
     *  • **too few references** — a scan that found almost nothing is a broken scan, not an empty
     *    shop.
     *
     * The DECISION is made once, here. The wording is not: a console operator reads English in
     * that terminal and an admin reads Arabic on the screen, so the refusal carries both and the
     * caller picks. What must never differ is whether a deletion is allowed — not how it is
     * phrased.
     *
     * @param  array<string, mixed>  $scan  the result of {@see self::scan()}
     * @return array{code: string, en: string, ar: string}|null
     */
    public static function refusal(array $scan, int $minReferences): ?array
    {
        if (($scan['scan_failed'] ?? false) === true) {
            return [
                'code' => 'partial_scan',
                'en' => 'at least one reference source could not be read (see the warnings above). '
                    .'A partial reference set cannot authorise a deletion. Fix the scan, then re-run.',
                'ar' => ManageText::t('media.refusal_partial_scan', 'تعذّرت قراءة مصدر مرجعي واحد على الأقل، والمجموعة الناقصة لا تصلح للحذف. أصلح القراءة ثم أعد الفحص.'),
            ];
        }

        $referenced = is_int($scan['referenced'] ?? null) ? $scan['referenced'] : 0;
        $matched = is_int($scan['matched'] ?? null) ? $scan['matched'] : 0;

        if ($matched === 0 && $referenced > 0) {
            return [
                'code' => 'no_coverage',
                'en' => 'not ONE of the '.$referenced.' referenced files exists in this tree. The directory and '
                    .'the database are describing different worlds — a partial copy, a wrong MEDIA_ROOT, or an '
                    .'unmounted share. Everything here would look like an orphan.',
                'ar' => ManageText::t('media.refusal_no_coverage', 'لا يوجد ولا ملف واحد من :count ملف مُشار إليه داخل هذه الشجرة. المجلد وقاعدة البيانات يتحدثان عن عالمين مختلفين (نسخة جزئية، أو MEDIA_ROOT خاطئ)، وكل شيء هنا سيبدو يتيمًا.', ['count' => $referenced]),
            ];
        }

        if ($referenced < $minReferences) {
            return [
                'code' => 'too_few_references',
                'en' => 'the reference scan found only '.$referenced.' referenced filename(s), below the floor of '
                    .$minReferences.'. That pattern means the scan failed (a renamed column, a table missing '
                    .'mid-rebuild), not that the tree is unused.',
                'ar' => ManageText::t('media.refusal_too_few_references', 'الفحص وجد :count اسم ملف مُشار إليه فقط، وهو أقل من الحد الأدنى (:minimum). هذا النمط يعني أن الفحص فشل، لا أن الشجرة غير مستخدمة.', ['count' => $referenced, 'minimum' => $minReferences]),
            ];
        }

        return null;
    }

    /**
     * A warning worth showing beside a report that is allowed to proceed.
     *
     * @param  array<string, mixed>  $scan
     */
    public static function coverageWarning(array $scan): ?string
    {
        $referenced = is_int($scan['referenced'] ?? null) ? $scan['referenced'] : 0;
        $matched = is_int($scan['matched'] ?? null) ? $scan['matched'] : 0;

        if ($matched > 0 && $matched * 10 < $referenced) {
            return ManageText::t(
                'media.coverage_warning',
                'تنبيه: :matched فقط من :referenced ملف مُشار إليه موجودة فعليًا في هذه الشجرة (:percent%). راجع إعداد MEDIA_ROOT قبل الاعتماد على قائمة الملفات اليتيمة.',
                [
                    'matched' => $matched,
                    'referenced' => $referenced,
                    'percent' => number_format(100 * $matched / max(1, $referenced), 1),
                ],
            );
        }

        return null;
    }

    /**
     * Delete the orphans of a scan that {@see self::refusal()} has cleared.
     *
     * @param  list<array{files: list<string>}>  $orphans
     * @return array{deleted: int, failed: int}
     */
    public function delete(array $orphans): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($orphans as $orphan) {
            foreach ($orphan['files'] as $file) {
                @unlink($file) ? $deleted++ : $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
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

        foreach (MediaPruneCommand::REFERENCE_COLUMNS as $schema => $tables) {
            // 'clean' → the default connection (named `mariadb`); 'legacy' → the legacy one.
            $connection = $schema === 'legacy' ? 'legacy' : null;
            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    try {
                        $values = DB::connection($connection)->table($table)->whereNotNull($column)->distinct()->pluck($column);
                    } catch (Throwable $e) {
                        $this->warnings[] = "reference scan: cannot read {$schema}.{$table}.{$column} — ".$e->getMessage();
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
        foreach (MediaPruneCommand::INLINE_TEXT_COLUMNS as $schema => $tables) {
            $connection = $schema === 'legacy' ? 'legacy' : null;
            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    try {
                        $rows = DB::connection($connection)->table($table)->where($column, 'like', '%Uploads_Images%')->pluck($column);
                    } catch (Throwable $e) {
                        $this->warnings[] = "inline scan: cannot read {$schema}.{$table}.{$column} — ".$e->getMessage();
                        $this->scanFailed = true;

                        continue;
                    }
                    foreach ($rows as $value) {
                        if (! is_string($value)) {
                            continue;
                        }
                        if (preg_match_all('#Uploads_Images/[^"\'\s>)]+/([^"\'\s>)]+)#i', $value, $matches) > 0) {
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
    public static function groupByMaster(string $directory): array
    {
        $groups = [];

        foreach (glob($directory.'/*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $name = basename($path);
            // `<base>-<width>.<ext>` → `<base>.webp`
            if (preg_match('/^(.+)-(\d{2,4})\.(avif|webp)$/', $name, $matches) === 1) {
                $groups[$matches[1].'.webp'][] = $path;

                continue;
            }
            $groups[$name][] = $path;
        }

        /** @var array<string, list<string>> $groups */
        return $groups;
    }
}
