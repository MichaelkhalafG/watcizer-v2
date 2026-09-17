<?php

declare(strict_types=1);

namespace App\Domain\Import;

use RuntimeException;

/**
 * The source file is not readable as the file it claims to be (🟡-4, 2026-09-17).
 *
 * ── Why this is its own type ─────────────────────────────────────────────────────────────────
 *
 * It separates "your file has a problem, here is where" from "the importer has a bug". The first is
 * an ordinary outcome of pointing a tool at a hand-edited spreadsheet, and the operator should get
 * a sentence naming the line. The second is ours, and should get a stack trace.
 *
 * Before this, both arrived as `RuntimeException` and both came out of the console as a trace —
 * forty lines of vendor frames for a missing comma, which tells the person holding the file
 * nothing and looks like the importer is broken.
 *
 * `ImportCatalogueCommand` catches this and prints the message alone, with a non-zero exit.
 * Everything else still propagates.
 */
final class ImportFileError extends RuntimeException {}
