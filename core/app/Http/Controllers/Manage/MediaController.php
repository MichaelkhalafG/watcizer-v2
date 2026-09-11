<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Media\MediaStore;
use App\Domain\Media\UnreadableUpload;
use ErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * POST /manage/media — the dashboard's one upload endpoint (wave 4A).
 *
 * It stores a file and hands back what a form needs to remember: the filename to save, the URL to
 * preview, and the renditions that were actually written. It does NOT attach anything to a product
 * or a brand — that is the owning screen's job in 4B, so this endpoint stays the same when those
 * screens arrive.
 *
 * Authorised by `can:manage-media`, so data-entry may upload and a role without that ability
 * cannot — checked on the server, like every other route.
 *
 * **Orphans are a known, accepted cost of this shape**: an upload followed by an abandoned form
 * leaves a file nothing references. The alternative (a staging area plus a promote step) buys
 * tidiness for real complexity, and images are cheap. A `media:prune` command that lists files no
 * row references belongs with the screens that create the rows — 4B.
 */
final class MediaController
{
    /** One wording for a rejected file, in both the message and the field error. */
    private const NOT_AN_IMAGE = 'هذا الملف ليس صورة صالحة. استخدم JPG أو PNG أو WebP.';

    /** A file that reached the server but cannot be READ — nothing about it can be checked. */
    private const UNREADABLE = 'تعذّر قراءة الملف المرفوع. أعد المحاولة أو اختر ملفًا آخر.';

    public function store(Request $request, MediaStore $store): JsonResponse
    {
        /*
         * Readability is checked BEFORE validation, because the `mimes:` rule guesses the type by
         * opening the file — and when that open fails it raises a PHP warning, which this
         * application turns into an `ErrorException` and the browser sees as a **500**.
         *
         * Found while porting the reviewer's real-file upload probe (item 9): on this workstation
         * the antivirus denies READ access to a temp file whose bytes look like a webshell, so
         * `finfo::file()` failed with "Invalid argument" on a file that `file_exists()` reported as
         * present. The AV is a local accident; the 500 was not — any host condition that makes an
         * upload unreadable (a killed transfer, a revoked temp directory, an AV lock) took the same
         * path. An upload nobody can read is refused like any other bad upload: 422, in Arabic.
         */
        $upload = $request->file('file');
        if ($upload instanceof UploadedFile && ! self::readable($upload)) {
            return response()->json([
                'message' => self::UNREADABLE,
                'errors' => ['file' => [self::UNREADABLE]],
            ], 422);
        }

        try {
            $request->validate([
                'type' => ['required', 'string', Rule::in(MediaStore::types())],
                'file' => [
                    'required', 'file',
                    'mimes:'.implode(',', MediaStore::allowedMimes()),
                    'max:'.config()->integer('media.upload.max_kilobytes'),
                ],
            ]);
        } catch (ErrorException $e) {
            /*
             * The `mimes:` rule guesses the type by OPENING the file, and `finfo` raises a PHP
             * warning when that open fails — which this application escalates to an
             * `ErrorException`, i.e. a 500. Refusing instead is what review 🟡-5 asked for: a file
             * whose type cannot be classified is a refused upload, not a server fault.
             *
             * `ValidationException` is not caught here and keeps its own 422 with field errors.
             */
            report($e);

            return response()->json([
                'message' => self::UNREADABLE,
                'errors' => ['file' => [self::UNREADABLE]],
            ], 422);
        }

        $file = $request->file('file');
        if (is_array($file) || $file === null) {
            return response()->json(['message' => 'Exactly one file is expected.'], 422);
        }

        try {
            $stored = $store->store($file, $request->string('type')->toString());
        } catch (UnreadableUpload $e) {
            /*
             * The FILE is the problem, not the server (review 🟡-5). `mimes:` passed — the name
             * and the declared type looked like an image — and the decode is where the truth came
             * out. That is a 422 carrying the same Arabic refusal the field errors use, so the
             * operator changes their file instead of reporting an outage.
             *
             * The log line stays: a run of these is worth seeing, whoever is at fault.
             */
            report($e);

            return response()->json([
                'message' => self::NOT_AN_IMAGE,
                'errors' => ['file' => [self::NOT_AN_IMAGE]],
            ], 422);
        } catch (RuntimeException $e) {
            // A host without GD/WebP, or an unwritable shared mount, is an operational fault:
            // report it as one instead of a 500 with a stack trace in a JSON body.
            report($e);

            return response()->json(['message' => 'تعذّر معالجة الصورة على هذا الخادم. راجع سجلات النظام.'], 500);
        }

        return response()->json($stored, 201);
    }

    /**
     * Can this upload's bytes actually be opened?
     *
     * `is_readable()` is not enough on Windows: a file the antivirus has locked reports readable
     * and still refuses the open. So the check is an actual `fopen`, suppressed, closed at once.
     */
    private static function readable(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        $path = is_string($path) && $path !== '' ? $path : $file->getPathname();
        if (! is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return true;
    }
}
