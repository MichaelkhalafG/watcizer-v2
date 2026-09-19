<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\PreSwitch;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\FullReplace;
use App\Support\ManageText;
use App\Support\Table\TableExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /manage/lookups/{list} — brands and the eleven lookup lists the product form consumes
 * (scope item 6).
 *
 * ONE controller for twelve lists, because they are one shape: a master row, an ar/en name, and
 * sometimes an extra column. `config('catalog.lookups')` is the whole definition, and the list key
 * from the URL is validated against it BEFORE anything is queried — a table name never comes from
 * a request string.
 *
 * Every row shows a USAGE COUNT, and delete refuses while it is non-zero. The foreign keys are
 * `RESTRICT` (M1 `restrictOnDelete()`), so the database would refuse anyway — with a 1451 that
 * reaches the team as a 500. The count is the message; the FK stays the mechanism.
 */
final class LookupController
{
    public function __construct(private readonly LookupWriter $lookups) {}

    public function index(Request $request, string $list): Response|StreamedResponse
    {
        $def = self::definition($list);

        $rows = array_map(
            function (array $row) use ($def): array {
                // An image column is stored as a filename and rendered from the shared tree,
                // exactly as a product image is (study §5.4: the DB holds a filename only).
                $extra = Coerce::arr($row['extra'] ?? null);
                foreach ($def['extra'] as $column => $field) {
                    $file = Coerce::nstr($extra[$column] ?? null);
                    if (($field['type'] ?? null) === 'image' && $file !== null) {
                        $extra[$column.'_url'] = ImageUrl::src($file);
                    }
                }
                $row['extra'] = $extra;

                return $row;
            },
            $this->lookups->rows($def['key']),
        );

        /*
         * `uses` is the column this file exists for: it is the number that decides whether a brand
         * or a colour may be retired, and reading three hundred of them off a screen is how a row
         * that is still in use gets deleted. The extra columns are whatever THIS list declares, so
         * the brands file carries a logo filename and the colours file a hex — and no per-list
         * code exists anywhere.
         */
        $columns = [
            'id' => ManageText::t('common.id', 'الرقم'),
            'name' => [ManageText::t('common.name_ar', 'الاسم (عربي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['name'] ?? null)['ar'] ?? null)],
            'name_en' => [ManageText::t('common.name_en', 'الاسم (إنجليزي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['name'] ?? null)['en'] ?? null)],
            'uses' => ManageText::t('lookups.usage_count', 'مرات الاستخدام'),
        ];
        foreach ($def['extra'] as $column => $field) {
            /*
             * A declared boolean exports as a WORD, not as a digit (item 11, 2026-09-18).
             *
             * The server now sends real booleans — see `LookupWriter::rows()` — and stringifying
             * one gives `'1'` for true and `''` for false, so an inactive brand would have left a
             * blank cell that reads as "nobody filled this in" rather than "switched off". The
             * bilingual export's own rule applies: an empty cell is information, so it must not be
             * spent on a value that is present and simply false.
             */
            $isBoolean = ($field['type'] ?? null) === 'boolean';

            $columns[$column] = [
                Coerce::str($field['label'] ?? $column),
                function (array $row) use ($column, $isBoolean): string {
                    $value = Coerce::arr($row['extra'] ?? null)[$column] ?? null;

                    if ($isBoolean) {
                        return $value === true
                            ? ManageText::t('common.yes', 'نعم')
                            : ManageText::t('common.no', 'لا');
                    }

                    return Coerce::str($value);
                },
            ];
        }

        $export = TableExport::wanted($request, 'lookup-'.$def['key'], $columns, $rows);
        if ($export !== null) {
            return $export;
        }

        return Inertia::render('Manage/Lookups/Index', [
            'list' => [
                'key' => $def['key'],
                'label' => $def['label'],
                'extra' => $def['extra'],
                /*
                 * WHAT the usage number counts — not where it is counted from (item 10, 2026-09-18).
                 *
                 * This used to be `usage_tables`: `catalog_product_watch_specs.case_size_unit_id`,
                 * rendered in a monospace span in front of a data-entry operator. The developer
                 * named it as the example of the whole class: *"Column names and table names in
                 * front of a data-entry operator."*
                 *
                 * The fix is the PROP, not the sentence around it. Rewording a note that still
                 * contained `catalog_product_watch_specs.case_size_unit_id` would have moved the
                 * problem one clause to the left. An operator needs to know the number means
                 * PRODUCTS — and then they can act on it. Where it comes from is this file's
                 * business.
                 *
                 * A TOKEN rather than a finished sentence, because the wording belongs on the
                 * screen with the rest of the copy: every one of the twelve lists is ultimately
                 * counting products, and the only distinction worth making is whether it counts
                 * them through their sizes and colours.
                 */
                'usage_counts' => self::usageCounts($def['usage']),
            ],
            'lists' => self::allLists(),
            'rows' => $rows,
            'pre_switch' => PreSwitch::state('lookup'),
        ]);
    }

    public function store(Request $request, string $list): RedirectResponse
    {
        $def = self::definition($list);
        $data = Coerce::arr($request->validate(LookupWriter::rules($def['key'])));

        try {
            $this->lookups->create($def['key'], $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name.ar' => $e->getMessage()]);
        }

        return back()->with('status', ManageText::t('lookups.added', 'تمت الإضافة.'));
    }

    public function update(Request $request, string $list, int $id): RedirectResponse
    {
        // Replaces the row, extras included: a PUT without `extra.hex` stored a colour with no
        // hex and `catalog/meta` served `color_value: null` ({@see FullReplace}).
        FullReplace::assert($request, ManageText::t('lookups.record', 'عنصر القائمة المرجعية'), 'name.ar');

        $def = self::definition($list);
        $data = Coerce::arr($request->validate(LookupWriter::rules($def['key'], $id)));

        try {
            $this->lookups->update($def['key'], $id, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name.ar' => $e->getMessage()]);
        }

        return back()->with('status', ManageText::t('lookups.saved', 'تم الحفظ.'));
    }

    public function destroy(Request $request, string $list, int $id): RedirectResponse
    {
        $def = self::definition($list);
        $result = $this->lookups->delete($def['key'], $id);

        return $result['deleted']
            ? back()->with('status', $result['reason'])
            : back()->withErrors(['delete' => $result['reason']]);
    }

    /**
     * `products` or `variants` — what the usage column is counting, in the operator's terms.
     *
     * Every declared usage pair on every one of the twelve lists points at a product or at
     * something that belongs to exactly one product, so the honest answer is always "products".
     * The one distinction an operator can act on is whether the reference is on the product itself
     * or on its sizes and colours, because that changes where they go to move it.
     *
     * @param  list<array{0: string, 1: string}>  $usage
     */
    private static function usageCounts(array $usage): string
    {
        foreach ($usage as [$table]) {
            if ($table === 'catalog_product_variants') {
                return 'variants';
            }
        }

        return 'products';
    }

    /**
     * The list named in the URL — or a 404.
     *
     * @return array{key: string, label: string, master: string, translations: string, fk: string, extra: array<string, array<string, mixed>>, usage: list<array{0: string, 1: string}>}
     */
    private static function definition(string $list): array
    {
        try {
            return LookupWriter::definition($list);
        } catch (InvalidArgumentException) {
            // A name that is not in the config is not an error message quoting the name back —
            // it is a URL that does not exist.
            abort(404);
        }
    }

    /** @return list<array{key: string, label: string, url: string}> */
    private static function allLists(): array
    {
        $out = [];
        foreach (LookupWriter::all() as $key => $def) {
            $out[] = [
                'key' => $key,
                'label' => $def['label'],
                'url' => route('manage.lookups.index', ['list' => $key]),
            ];
        }

        return $out;
    }
}
