<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\PreSwitch;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\FullReplace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

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

    public function index(Request $request, string $list): Response
    {
        $def = self::definition($list);

        return Inertia::render('Manage/Lookups/Index', [
            'list' => [
                'key' => $def['key'],
                'label' => $def['label'],
                'extra' => $def['extra'],
                // The screen tells the team WHERE a row is used, not just how often, so "23"
                // is actionable instead of alarming.
                'usage_tables' => array_map(fn (array $pair): string => $pair[0].'.'.$pair[1], $def['usage']),
            ],
            'lists' => self::allLists(),
            'rows' => array_map(
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
            ),
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

        return back()->with('status', 'تمت الإضافة.');
    }

    public function update(Request $request, string $list, int $id): RedirectResponse
    {
        // Replaces the row, extras included: a PUT without `extra.hex` stored a colour with no
        // hex and `catalog/meta` served `color_value: null` ({@see FullReplace}).
        FullReplace::assert($request, 'عنصر القائمة المرجعية', 'name.ar');

        $def = self::definition($list);
        $data = Coerce::arr($request->validate(LookupWriter::rules($def['key'], $id)));

        try {
            $this->lookups->update($def['key'], $id, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name.ar' => $e->getMessage()]);
        }

        return back()->with('status', 'تم الحفظ.');
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
