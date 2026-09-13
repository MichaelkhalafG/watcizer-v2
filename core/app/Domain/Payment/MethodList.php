<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The one ordered list of payment methods a storefront offers — and the ONE place the collapse
 * rule lives (study §3.9.5).
 *
 * ── The rule, and the wording that was open until the 4C brief settled it ────────────────────
 *
 * A storefront may offer `card` through Paymob AND through Fawry: the schema allows it on purpose,
 * because holding both contracts is what makes moving card traffic between them possible. But the
 * customer must never see "Card" twice, so the candidate rows collapse to ONE entry per method key.
 *
 * **LOWEST `sort` WINS** (developer decision, wave-4C brief). The study flagged the phrase
 * "highest-sort provider" as ambiguous and worth one sentence of confirmation, because the two
 * readings pick opposite providers: `sort` ascending IS the display order the admin drags, so the
 * row that appears FIRST is the winner. `p.provider, m.method, m.id` are the deterministic
 * tie-break, so the list never reorders itself between two requests.
 *
 * ── Why the winner is decided HERE and not at checkout ───────────────────────────────────────
 *
 * The payload the customer receives names the METHOD and not the provider, and the frontend posts
 * back the winning row's id. So the routing decision is taken once, at list time, and "the customer
 * does not know who is behind each entry" is true in the data rather than only in the rendering.
 * A second resolution at checkout would be a second chance to disagree.
 *
 * The dashboard reads the same candidates through {@see self::forAdmin()} — same query, same order,
 * nothing collapsed — so the admin can see which contract currently wins each key and which rows
 * are configured but not serving. "Which provider is taking this money" must never be a mystery.
 */
final class MethodList
{
    /**
     * What the CUSTOMER sees: one entry per method key, in the storefront's own order.
     *
     * @return list<array{id: int, method: string, label: string, icon: string|null, sort: int}>
     */
    public static function forCustomer(int $storefrontId, string $locale): array
    {
        $out = [];
        $seen = [];
        foreach (self::candidates($storefrontId, $locale) as $row) {
            $method = $row['method'];
            if (isset($seen[$method])) {
                continue;                       // a later row for the same key: the first one won
            }
            $seen[$method] = true;
            $out[] = [
                'id' => $row['id'],
                'method' => $method,
                'label' => $row['label'],
                'icon' => $row['icon'],
                'sort' => $row['sort'],
            ];
        }

        return $out;
    }

    /**
     * What the ADMIN sees: every candidate row, each told whether it currently SERVES its key and
     * which provider took it if not — plus a label-mismatch flag.
     *
     * The mismatch matters because the customer sees the WINNER's label: if the Paymob `card` row
     * reads "Card" and the Fawry one reads "Bank card", re-ordering the two silently changes the
     * wording on the storefront. The schema cannot prevent it (labels are per row, which every
     * non-duplicated method needs), so the screen warns.
     *
     * @return list<array{id: int, method: string, label: string, icon: string|null, sort: int, provider: string, provider_id: int, is_enabled: bool, provider_enabled: bool, serves: bool, served_by: string|null, label_mismatch: bool}>
     */
    public static function forAdmin(int $storefrontId, string $locale): array
    {
        $candidates = self::candidates($storefrontId, $locale, onlyEnabled: false);

        // The winner per key, computed from the ENABLED rows only and in the same order the
        // customer list uses — a disabled row never takes the money.
        $winner = [];
        foreach ($candidates as $row) {
            if (! $row['is_enabled'] || ! $row['provider_enabled']) {
                continue;
            }
            $winner[$row['method']] ??= $row;
        }

        // A label mismatch is a property of the KEY, across its enabled rows.
        $labels = [];
        foreach ($candidates as $row) {
            if ($row['is_enabled'] && $row['provider_enabled']) {
                $labels[$row['method']][$row['label']] = true;
            }
        }

        $out = [];
        foreach ($candidates as $row) {
            $key = $row['method'];
            $winningId = isset($winner[$key]) ? $winner[$key]['id'] : null;
            $out[] = $row + [
                'serves' => $winningId === $row['id'],
                'served_by' => $winningId === null || $winningId === $row['id'] ? null : $winner[$key]['provider'],
                'label_mismatch' => count($labels[$key] ?? []) > 1,
            ];
        }

        return $out;
    }

    /**
     * The candidate rows, in the storefront's own order.
     *
     * ORDER BY `m.sort, p.provider, m.method, m.id` — the sort the admin drags, then a total
     * tie-break so two requests cannot disagree about who wins a duplicated key.
     *
     * @return list<array{id: int, method: string, label: string, icon: string|null, sort: int, provider: string, provider_id: int, is_enabled: bool, provider_enabled: bool}>
     */
    private static function candidates(int $storefrontId, string $locale, bool $onlyEnabled = true): array
    {
        $query = DB::table('storefront_payment_methods as m')
            ->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
            ->leftJoin('storefront_payment_method_translations as t', function (JoinClause $join) use ($locale): void {
                $join->on('t.storefront_payment_method_id', '=', 'm.id')->where('t.locale', '=', $locale);
            })
            ->where('p.storefront_id', $storefrontId)
            ->orderBy('m.sort')->orderBy('p.provider')->orderBy('m.method')->orderBy('m.id');

        if ($onlyEnabled) {
            $query->where('p.is_enabled', true)->where('m.is_enabled', true);
        }

        $out = [];
        foreach (
            $query->get([
                'm.id', 'm.method', 'm.icon', 'm.sort', 'm.is_enabled',
                'p.provider', 'p.id as provider_id', 'p.is_enabled as provider_enabled', 't.label',
            ]) as $raw
        ) {
            $row = Row::cast($raw);
            $method = Row::str($row, 'method');
            $out[] = [
                'id' => Row::int($row, 'id'),
                'method' => $method,
                // A method with no translation in this locale falls back to its key rather than
                // rendering blank: an unlabelled button is worse than an English one.
                'label' => Coerce::str(Row::nstr($row, 'label'), $method),
                'icon' => Row::nstr($row, 'icon'),
                'sort' => Row::int($row, 'sort'),
                'provider' => Row::str($row, 'provider'),
                'provider_id' => Row::int($row, 'provider_id'),
                'is_enabled' => Row::bool($row, 'is_enabled'),
                'provider_enabled' => Row::bool($row, 'provider_enabled'),
            ];
        }

        return $out;
    }

    /**
     * Resolve what a customer posted back to the row that will take the money.
     *
     * Accepts the v2 shape (`payment_method_id`) and the legacy string, and both must land on the
     * SAME contract — a legacy client and a v2 client posting "card" settling into two different
     * merchant accounts is the failure this method exists to prevent. `cash → cod`,
     * `whatsapp → whatsapp`, `card`/`paymob` → the winning `card` row.
     *
     * @return array{id: int, method: string, provider: string, provider_id: int}|null
     */
    public static function resolve(int $storefrontId, string $locale, int|string|null $posted): ?array
    {
        // `ctype_digit('')` is false, so the empty string is already excluded here.
        if (is_int($posted) || (is_string($posted) && ctype_digit($posted))) {
            $id = (int) $posted;
            foreach (self::candidates($storefrontId, $locale) as $row) {
                if ($row['id'] === $id) {
                    return ['id' => $row['id'], 'method' => $row['method'], 'provider' => $row['provider'], 'provider_id' => $row['provider_id']];
                }
            }

            return null;                        // unknown, disabled or another storefront's row
        }

        $legacy = is_string($posted) ? mb_strtolower(trim($posted)) : '';
        $wanted = match ($legacy) {
            'cash', 'cod' => 'cod',
            'whatsapp' => 'whatsapp',
            'card', 'paymob' => 'card',
            default => $legacy,
        };
        if ($wanted === '') {
            return null;
        }

        foreach (self::forCustomer($storefrontId, $locale) as $row) {
            if ($row['method'] === $wanted) {
                $full = self::candidates($storefrontId, $locale);
                foreach ($full as $candidate) {
                    if ($candidate['id'] === $row['id']) {
                        return ['id' => $candidate['id'], 'method' => $candidate['method'], 'provider' => $candidate['provider'], 'provider_id' => $candidate['provider_id']];
                    }
                }
            }
        }

        return null;
    }
}
