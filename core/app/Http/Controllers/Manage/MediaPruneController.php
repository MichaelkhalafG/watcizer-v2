<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Media\MediaAudit;
use App\Domain\Media\MediaStore;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The media cleanup screen (wave 4D, task C4) — what `media:prune` sees, with a button.
 *
 * ── Why a screen at all, when a command already exists ───────────────────────────────────────
 *
 * Because the command is run by whoever has a terminal, and the person who knows whether a file
 * matters is the one with the dashboard. The screen answers the question they actually have —
 * *"how much of this tree is rubbish, and is it safe to clear it?"* — in the two numbers that
 * matter and one sentence about whether a deletion is allowed at all.
 *
 * ── Every guard is the COMMAND'S guard ───────────────────────────────────────────────────────
 *
 * The scan, the three refusals and the deletion all live in {@see MediaAudit}, which the command
 * calls too. There is one definition of "orphan" and one of "may I delete", and this controller
 * adds exactly two things of its own:
 *
 *  1. **admin only** — `manage-catalog` is not enough for a button that removes files a live
 *     storefront serves;
 *  2. **a typed confirmation** — the operator types the number of files they are about to remove.
 *     Not a modal they can click through: the number changes with the scan, so it cannot be muscle
 *     memory, and typing it means they read it.
 *
 * The age floor stays at the command's default. A file younger than a week is never in the list,
 * so an upload that is mid-form while somebody clears the tree is safe.
 */
final class MediaPruneController
{
    /**
     * How many deleted paths one log row carries.
     *
     * Enough to answer "was my image in there?" for a normal prune, and bounded so an enormous one
     * cannot write a megabyte of JSON into a column a person is meant to read. The COUNT is never
     * truncated — only the list, and the row says by how much.
     */
    private const LOGGED_PATHS = 500;

    /** The command's defaults, so screen and terminal report the same thing. */
    private const MIN_AGE_DAYS = 7;

    private const MIN_REFERENCES = 100;

    public function index(MediaAudit $audit): Response
    {
        $scan = $audit->scan(MediaStore::types(), self::MIN_AGE_DAYS);

        return Inertia::render('Manage/Media/Prune', self::props($scan));
    }

    public function destroy(Request $request, MediaAudit $audit): RedirectResponse
    {
        $scan = $audit->scan(MediaStore::types(), self::MIN_AGE_DAYS);

        $refusal = MediaAudit::refusal($scan, self::MIN_REFERENCES);
        if ($refusal !== null) {
            return back()->withErrors([
                'confirm' => ManageText::t('media.cannot_delete', 'لا يمكن الحذف: :reason', ['reason' => $refusal['ar']]),
            ]);
        }

        /*
         * The confirmation is checked against THIS scan, not the one the screen rendered. If the
         * tree changed between the page load and the click — another upload, another admin — the
         * number no longer matches and nothing is deleted. That is the point of typing a number
         * rather than clicking "yes".
         */
        $typed = Coerce::int($request->input('confirm'));
        if ($typed !== $scan['files']) {
            return back()->withErrors([
                'confirm' => ManageText::t(
                    'media.confirm_mismatch',
                    'الرقم لا يطابق عدد الملفات الحالي (:count). تغيّرت الشجرة منذ فتح الصفحة، أو أُدخل رقم خاطئ — ولم يُحذف شيء.',
                    ['count' => $scan['files']],
                ),
            ]);
        }

        if ($scan['orphans'] === []) {
            return back()->with('status', ManageText::t('media.nothing_to_delete', 'لا توجد ملفات يتيمة لحذفها.'));
        }

        $result = $audit->delete($scan['orphans']);

        /*
         * ── This row is the ONLY record that will exist (2026-10-05) ──────────────────
         *
         * Every other screen's log row describes a database row somebody can still go and look at.
         * This one describes FILES that no longer exist anywhere — there is no table to inspect,
         * no soft delete, no rebuild that brings them back. So the paths go IN, not just the count:
         * a row saying "412 files deleted" answers nothing an hour later, and the one question
         * anybody will ask is whether a particular image was among them.
         *
         * `subject_id` is null because there is no row to point at, and everything goes in as
         * `$after` rather than `$before` — this row is an EVENT, not a diff. `— ← 412` reads as
         * "this happened"; the other way round would read as "412 files used to be there", which
         * is not what the row means.
         *
         * ── Two things that silently emptied this row, and what they cost ────────────
         *
         * Written first with the payload as `$before` and no `$after`, which produced a row with a
         * NULL `changes` — `ActivityLog::diff()` walked `$after` alone. It has since been fixed to
         * walk both sides (and that fix is what makes every DELETE in this codebase carry what it
         * deleted), but the argument stays on the `$after` side because that is what it means.
         *
         * And the paths went in as an ARRAY, which `ActivityLog::readable()` json-encodes and clips
         * to 300 characters — about eight filenames out of five hundred, with no marker saying so.
         * They go in as one newline-joined STRING instead: a scalar passes through `readable()`
         * untouched, `LIKE '%name.webp%'` finds a single file in it, and the cap below is the only
         * thing that trims the list.
         */
        ActivityLog::record(
            'media_files',
            null,
            ActivityLog::DELETED,
            after: self::auditPayload($scan, $result),
            label: ManageText::t(
                'media.prune_log_label',
                'حذف :count ملف يتيم',
                ['count' => $result['deleted']],
            ),
        );

        /*
         * ONE sentence per outcome rather than a sentence assembled from three fragments: the
         * "…and N could not be deleted…" clause sits in the MIDDLE of the sentence, and a
         * translator handed the pieces separately cannot put it anywhere else.
         */
        $reclaimed = self::humanBytes($scan['bytes']);

        return back()->with('status', $result['failed'] > 0
            ? ManageText::t(
                'media.deleted_with_failures',
                'حُذف :count ملف، وتعذّر حذف :failed ملف (صلاحيات؟). استُرجعت :size.',
                ['count' => $result['deleted'], 'failed' => $result['failed'], 'size' => $reclaimed],
            )
            : ManageText::t(
                'media.deleted',
                'حُذف :count ملف. استُرجعت :size.',
                ['count' => $result['deleted'], 'size' => $reclaimed],
            ));
    }

    /**
     * What the one surviving record of a prune says.
     *
     * ── Public, and separate from the call, so it can be TESTED ──────────────────────
     *
     * Every other screen's audit row can be proved end to end: make the change, read the row. This
     * one cannot. A prune is refused on any machine whose media tree is not the live one — that is
     * the `no_coverage` guard, and it is the guard that stops a developer's workstation deleting
     * its own 74 files — so the only way to reach this payload through the screen would be to make
     * a real prune succeed, which means really deleting somebody's images. The assembly is
     * therefore lifted out and tested directly, and the test then feeds the result through
     * {@see ActivityLog::record()} to prove the row survives the trip intact.
     *
     * That is not an academic precaution. The first version of this payload lost its paths twice
     * over — once to `diff()` reading `$after` only, once to `readable()` clipping an array at 300
     * characters — and the log swallows its own exceptions, so neither loss could have announced
     * itself. It would have been discovered by somebody asking which file had gone, and finding
     * that the row which existed for exactly that question could not answer it.
     *
     * @param  array<string, mixed>  $scan  the result of {@see MediaAudit::scan()}
     * @param  array{deleted: int, failed: int}  $result
     * @return array<string, mixed>
     */
    public static function auditPayload(array $scan, array $result): array
    {
        /*
         * `orphans` is grouped by MASTER — one entry per image, carrying its renditions — and each
         * group's `files` are ABSOLUTE paths on this machine, because that is what `delete()`
         * unlinks. Flattened to one line per file, since what somebody searches the log for is a
         * file, not a group.
         *
         * Stored as `<folder>/<name>`, not as the absolute path: the drive letter and the install
         * directory are this server's and say nothing, while `product/8f2a….webp` is the shape the
         * database stores and the shape somebody will paste into a search. (The first version
         * joined the folder onto the full path and produced `product/D:/…/product/x.webp`.)
         */
        $paths = [];
        foreach (is_array($scan['orphans'] ?? null) ? $scan['orphans'] : [] as $group) {
            $folder = Coerce::str(is_array($group) ? ($group['folder'] ?? null) : null);
            $files = is_array($group) && is_array($group['files'] ?? null) ? $group['files'] : [];
            foreach ($files as $file) {
                $paths[] = $folder.'/'.basename(Coerce::str($file));
            }
        }
        $listed = array_slice($paths, 0, self::LOGGED_PATHS);

        return [
            'files_deleted' => $result['deleted'],
            'files_failed' => $result['failed'],
            'bytes_reclaimed' => Coerce::int($scan['bytes'] ?? null),
            // ONE newline-joined string, not a list: `ActivityLog::readable()` json-encodes a
            // non-scalar and clips it to 300 characters — eight filenames out of five hundred,
            // with nothing saying the rest were dropped. A scalar passes through untouched, and
            // `LIKE '%name.webp%'` still finds a single file inside it.
            'paths' => implode("\n", $listed),
            'paths_truncated' => count($paths) - count($listed),
        ];
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private static function props(array $scan): array
    {
        $orphans = is_array($scan['orphans'] ?? null) ? $scan['orphans'] : [];

        $sample = [];
        foreach (array_slice($orphans, 0, 50) as $orphan) {
            $row = Coerce::arr($orphan);
            $sample[] = [
                'type' => Coerce::str($row['type'] ?? null),
                'folder' => Coerce::str($row['folder'] ?? null),
                'master' => Coerce::str($row['master'] ?? null),
                'renditions' => max(0, count(Coerce::arr($row['files'] ?? null)) - 1),
                'bytes' => Coerce::int($row['bytes'] ?? null),
            ];
        }

        return [
            'types' => $scan['types'] ?? [],
            'referenced' => $scan['referenced'] ?? 0,
            'matched' => $scan['matched'] ?? 0,
            'orphan_masters' => count($orphans),
            'orphan_files' => $scan['files'] ?? 0,
            'bytes' => $scan['bytes'] ?? 0,
            'human_bytes' => self::humanBytes(Coerce::int($scan['bytes'] ?? null)),
            'sample' => $sample,
            'sample_is_partial' => count($orphans) > 50,
            'warnings' => $scan['warnings'] ?? [],
            // The refusal is shown BEFORE the operator types anything: the honest screen says "you
            // cannot delete from here today, and this is why" rather than failing on submit.
            'refusal' => self::refusalText($scan),
            'coverage_warning' => MediaAudit::coverageWarning($scan),
            'min_age_days' => self::MIN_AGE_DAYS,
        ];
    }

    /**
     * The refusal in the operator's language, or null when deleting is allowed.
     *
     * @param  array<string, mixed>  $scan
     */
    private static function refusalText(array $scan): ?string
    {
        $refusal = MediaAudit::refusal($scan, self::MIN_REFERENCES);

        return $refusal === null ? null : $refusal['ar'];
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 1).' KB';
    }
}
