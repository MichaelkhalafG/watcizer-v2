<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

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
