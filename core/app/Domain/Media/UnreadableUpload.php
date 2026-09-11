<?php

namespace App\Domain\Media;

use RuntimeException;

/**
 * The uploaded BYTES are not an image this host can decode (review 🟡-5).
 *
 * ── Why a separate class and not another RuntimeException ─────────────────────────────────────
 *
 * `ImagePipeline` throws for two unrelated kinds of failure, and the upload endpoint answered both
 * with a 500:
 *
 *   • the HOST cannot do the work — GD missing, WebP unsupported, the shared `Uploads_Images`
 *     mount unwritable. Nothing the operator uploads will help, and 500 is the honest answer.
 *   • the FILE is not a usable image. `mimes:` has already passed at that point — the extension
 *     and the declared type looked right — and only the decode reveals that the bytes are
 *     something else (a renamed document, a truncated download, a file that never finished
 *     copying). That is a rejected upload, and 500 tells the operator their dashboard is broken
 *     when in fact their file is.
 *
 * The difference matters on the screen: a 500 makes the team stop and call the developer, a 422
 * makes them pick a different file. Same log line either way — the report() call is kept, because
 * a sudden run of unreadable uploads is worth seeing.
 */
final class UnreadableUpload extends RuntimeException {}
