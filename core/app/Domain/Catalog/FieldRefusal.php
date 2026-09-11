<?php

namespace App\Domain\Catalog;

use RuntimeException;

/**
 * A writer's refusal that knows WHICH FIELD it is about.
 *
 * ── Why this exists ───────────────────────────────────────────────────────────────────────────
 *
 * The catalogue writers refuse in Arabic, and the controllers turn each refusal into a validation
 * error so it appears beside the thing the operator must change. Deciding which field that is was
 * done by reading the message:
 *
 *     $field = str_contains($e->getMessage(), 'الرابط') ? 'slug' : 'is_visible';
 *
 * That works until a new refusal is worded differently. It happened immediately: the
 * unslugifiable-slug refusal added in review 🟡-4 says «لا يمكن تحويل … إلى رابط» — about the slug,
 * containing «رابط» but not «الرابط» — so it landed on the VISIBILITY field, and the screen told
 * the operator their product could not be shown while the real problem was the slug they typed.
 * A test caught it; the next one might not.
 *
 * So the field travels WITH the refusal. The message stays the operator's sentence and nothing
 * parses it. A plain `RuntimeException` from a writer still means "about the action" and keeps its
 * old destination, which is why this is additive rather than a rewrite of every throw.
 */
final class FieldRefusal extends RuntimeException
{
    /** @param  string  $field  the form field this refusal belongs beside, e.g. `slug` */
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }

    /** The field for any refusal: the declared one, or the visibility toggle for an action-level one. */
    public static function fieldOf(RuntimeException $e, string $default = 'is_visible'): string
    {
        return $e instanceof self ? $e->field() : $default;
    }
}
