<?php

namespace App\Support\Table;

use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dashboard's one CSV export.
 *
 * ── What an export is, here ──────────────────────────────────────────────────────────────────
 *
 * A snapshot of THE VIEW THE OPERATOR IS LOOKING AT — their search, their filters, their sort —
 * and never "the whole table". A screen showing 43 out-of-stock watches exports 43 rows; that is
 * the number the person asked for, and an export that quietly returned 7 447 would be a different
 * answer to a question nobody asked.
 *
 * ── Why it cannot leak a column ──────────────────────────────────────────────────────────────
 *
 * The export does not build its own rows. It runs the SAME query with the SAME filters through the
 * SAME row callback the screen renders from, then picks named keys out of the result. So a column
 * the operator's role never put in the payload cannot appear in the file: there is nothing to pick
 * it from. Credentials, cost prices a role is not shown, internal ids a screen hides — all absent
 * for the same structural reason, not because a list of exclusions was maintained by hand.
 *
 * That also means the export needs NO route of its own. `?export=csv` on the index URL is answered
 * by the index action, behind the index's middleware, gates and storefront scope. There is no
 * second authorisation path to keep in step with the first.
 *
 * ── Streamed, not built ──────────────────────────────────────────────────────────────────────
 *
 * `cursor()` over the applied query, a batch at a time into `php://output`. The ledger is already
 * past 100 000 movements and the product list will pass 10 000 after the switch; building either
 * in memory to hand PHP one string is how an export becomes a 502 the day the catalogue grows.
 *
 * ── Two things Excel needs, and one thing it must not get ───────────────────────────────────
 *
 *  • a UTF-8 BOM, or Excel reads Arabic as mojibake — this is the whole reason the file starts
 *    with three bytes nobody can see;
 *  • the row count in the FILENAME rather than a banner line, because a line above the headers
 *    stops Excel (and every other tool) from treating row 1 as the header row. `2128-rows` in the
 *    name is visible before the file is even opened;
 *  • and no live formula. A product title beginning `=`, `+`, `-`, `@` is a cell Excel EXECUTES
 *    (`=HYPERLINK(...)`, `=cmd|…`), so any value starting with one is prefixed with an apostrophe.
 *    Our catalogue takes its titles from a supplier spreadsheet, which is exactly where such a
 *    value comes from.
 */
final class TableExport
{
    /** Rows per batch: enough that `prepare()` is worth its round trip, small enough to stay flat. */
    private const BATCH = 500;

    /**
     * @param  list<array{key: string, label: string, value: (callable(array<string, mixed>): string)|null}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows  the mapped rows, already in order
     */
    public static function respond(string $name, int $total, array $columns, iterable $rows): StreamedResponse
    {
        $filename = self::filename($name, $total);

        return new StreamedResponse(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // The BOM. Without it Excel opens Arabic as Ù…Ù†ØªØ¬ and the team blames the data.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_map(static fn (array $column): string => $column['label'], $columns));

            $written = 0;
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $value = $column['value'] !== null ? ($column['value'])($row) : self::scalar($row[$column['key']] ?? null);
                    $line[] = self::defuse($value);
                }
                fputcsv($out, $line);

                // Hand each batch to the client instead of holding it: an export the browser shows
                // progressing is an export nobody cancels and re-runs.
                if (++$written % self::BATCH === 0) {
                    flush();
                }
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // A streamed body has no length; saying so stops a proxy from buffering it to find one.
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * The same file, for a screen that is NOT on `TableQuery`.
     *
     * Four screens ship a whole prepared list rather than a paginated query — the category TREE,
     * the lookup lists, the granted roles, the unit cleanup. They are bounded (tens to hundreds of
     * rows), they have no server-side filters, and forcing them onto a paginated table to gain an
     * export would be the tail wagging the dog. They get the identical writer instead: same BOM,
     * same filename shape, same formula defusing, same `?export=csv` on the screen's own route and
     * therefore the screen's own gate.
     *
     * Returns null when this request is not asking for the file.
     *
     * @param  array<string, string|array{0: string, 1: callable(array<string, mixed>): string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function wanted(Request $request, string $name, array $columns, iterable $rows): ?StreamedResponse
    {
        if ($request->query('export') !== 'csv') {
            return null;
        }

        // The same refusal the paginated screens use — one rule, both entry points.
        TableQuery::assertMayExport();

        $declared = [];
        foreach ($columns as $key => $column) {
            $declared[] = is_array($column)
                ? ['key' => $key, 'label' => $column[0], 'value' => $column[1]]
                : ['key' => $key, 'label' => $column, 'value' => null];
        }

        $all = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);

        return self::respond($name, count($all), $declared, $all);
    }

    /** `products-2026-09-14-1432-2128-rows.csv` — ASCII, sortable, and the count before opening. */
    public static function filename(string $name, int $total): string
    {
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower($name)) ?? 'export';

        return trim($slug, '-').'-'.now()->format('Y-m-d-Hi').'-'.$total.'-rows.csv';
    }

    /**
     * A prop is whatever the screen needed it to be. Flatten the shapes a row actually carries;
     * anything else becomes JSON rather than "Array", because a cell reading `[object]` is a bug
     * report waiting to happen.
     */
    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? ManageText::t('common.yes', 'نعم') : ManageText::t('common.no', 'لا');
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            // `{ar: '…', en: '…'}` is the commonest shape on this dashboard, and the Arabic one is
            // what the screen shows.
            if (array_key_exists('ar', $value)) {
                return Coerce::str($value['ar']);
            }
            if (array_is_list($value) && $value !== [] && ! is_array($value[0])) {
                return implode(' | ', array_map(static fn (mixed $item): string => Coerce::str($item), $value));
            }
        }

        return Coerce::str(json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Stop a spreadsheet from EXECUTING a cell.
     *
     * Excel, LibreOffice and Sheets all treat a leading `=`, `+`, `-`, `@`, tab or CR as the start
     * of a formula. Our product titles come from a supplier's spreadsheet, so this is not a
     * hypothetical class of value.
     */
    private static function defuse(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
