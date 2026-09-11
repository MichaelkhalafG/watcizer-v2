<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Media\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function store(Request $request, MediaStore $store): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', Rule::in(MediaStore::types())],
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', MediaStore::allowedMimes()),
                'max:'.config()->integer('media.upload.max_kilobytes'),
            ],
        ]);

        $file = $request->file('file');
        if (is_array($file) || $file === null) {
            return response()->json(['message' => 'Exactly one file is expected.'], 422);
        }

        try {
            $stored = $store->store($file, $request->string('type')->toString());
        } catch (RuntimeException $e) {
            // A host without GD/WebP, or an unwritable shared mount, is an operational fault:
            // report it as one instead of a 500 with a stack trace in a JSON body.
            report($e);

            return response()->json(['message' => 'تعذّر معالجة الصورة على هذا الخادم. راجع سجلات النظام.'], 500);
        }

        return response()->json($stored, 201);
    }
}
