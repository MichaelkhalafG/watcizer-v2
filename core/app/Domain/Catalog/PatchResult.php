<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * What a PARTIAL update actually changed — the return value of every patcher (wave 4D).
 *
 * ── Why the result is a value object and not a bool ──────────────────────────────────────────
 *
 * Three of 4D's non-negotiables are properties of this object rather than of the write:
 *
 *  - **"a summary of what changed"** — the importer's apply step reports per row, per field, from
 *    what to what. That report is only as honest as the diff it is built from, so the diff is
 *    computed against the STORED values by the writer itself, not assembled afterwards from the
 *    payload (which would report what was asked for, not what happened).
 *  - **"idempotent: the same file twice changes nothing the second time"** — provable exactly when
 *    a second apply returns `isNoop()`. A bool return could not tell "wrote the same values again"
 *    from "wrote nothing".
 *  - **"anything the sheet expresses that the model can't hold is listed in the report, never
 *    silently dropped"** — `$derived` and `$ignored` are where that lands.
 *
 * `$derived` deserves its own line: a partial update may legitimately change a column the caller
 * did NOT name. The one case today is the sale-price contract — a patch that lowers
 * `selling_price` below a stored `sale_price` invalidates that sale, and the stored value has to
 * be nulled or the storefront prices a cart wrong (the price/total contract). That is a real
 * change to an unnamed column, so it is reported as one, under its own heading. A patcher may
 * never make such a change without listing it here.
 */
final readonly class PatchResult
{
    /**
     * @param  array<string, array{from: string|null, to: string|null}>  $changes  field => before/after, as stored
     * @param  list<string>  $derived  fields in `$changes` the caller did NOT name
     * @param  array<string, string>  $ignored  field => why it could not be applied
     */
    public function __construct(
        public array $changes = [],
        public array $derived = [],
        public array $ignored = [],
    ) {}

    public function isNoop(): bool
    {
        return $this->changes === [];
    }

    public function changedCount(): int
    {
        return count($this->changes);
    }

    /** @return list<string> */
    public function changedFields(): array
    {
        return array_keys($this->changes);
    }

    /**
     * One line per change, for an operator's report. Arabic-neutral on purpose: these are column
     * names and values, and a translated column name would make the report unsearchable.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $out = [];
        foreach ($this->changes as $field => $change) {
            $line = $field.': '.($change['from'] ?? '—').' → '.($change['to'] ?? '—');
            if (in_array($field, $this->derived, true)) {
                // Said out loud: the caller did not ask for this one.
                $line .= ' (derived)';
            }
            $out[] = $line;
        }
        foreach ($this->ignored as $field => $why) {
            $out[] = $field.': ignored — '.$why;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'changes' => $this->changes,
            'derived' => $this->derived,
            'ignored' => $this->ignored,
            'noop' => $this->isNoop(),
        ];
    }
}
