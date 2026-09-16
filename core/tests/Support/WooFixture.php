<?php

namespace Tests\Support;

/**
 * A WooCommerce export file, built row by row (wave 4D importers).
 *
 * The 52 columns and their ORDER are the real export's, because a reader test written against an
 * invented CSV proves the test's own assumptions and nothing about the file we actually received.
 * Only the columns a test names carry a value; the rest are empty exactly as they are in the file,
 * where 30 of the 52 are empty in every row.
 */
final class WooFixture
{
    private const HEADER = 'ID,Type,SKU,GTIN,Name,Published,Is featured?,Visibility in catalog,Short description,'
        .'Description,Date sale price starts,Date sale price ends,Tax status,Tax class,In stock?,Stock,'
        .'Low stock amount,Backorders allowed?,Sold individually?,Weight (kg),Length (cm),Width (cm),Height (cm),'
        .'Allow customer reviews?,Purchase note,Sale price,Regular price,Categories,Tags,Shipping class,Images,'
        .'Download limit,Download expiry days,Parent,Grouped products,Upsells,Cross-sells,External URL,'
        .'Button text,Position,Swatches Attributes,Brands,Attribute 1 name,Attribute 1 value(s),'
        .'Attribute 1 visible,Attribute 1 global,Attribute 1 default,Attribute 2 name,Attribute 2 value(s),'
        .'Attribute 2 visible,Attribute 2 global,Attribute 2 default';

    /** Column name => its index in the export. */
    private const INDEX = [
        'ID' => 0, 'Type' => 1, 'SKU' => 2, 'Name' => 4, 'Published' => 5, 'Visibility in catalog' => 7,
        'Description' => 9, 'Stock' => 15, 'Sale price' => 25, 'Regular price' => 26, 'Categories' => 27,
        'Images' => 30, 'Parent' => 33, 'Attribute 1 name' => 42, 'Attribute 1 value(s)' => 43,
    ];

    /** Write a file with these rows and return its path. The caller deletes it. */
    public static function file(string $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'woo').'.csv';
        file_put_contents($path, self::HEADER."\n".$rows."\n");

        return $path;
    }

    /**
     * One row.
     *
     * @param  array<string, string|int>  $values
     */
    public static function row(array $values): string
    {
        $columns = array_fill(0, 52, '');

        foreach ($values as $column => $value) {
            $text = (string) $value;
            // The real file quotes the Categories column, which is the only one that contains a
            // comma — so the fixture quotes anything that does, for the same reason.
            $columns[self::INDEX[$column]] = str_contains($text, ',') ? '"'.$text.'"' : $text;
        }

        return implode(',', $columns);
    }

    /**
     * Several rows, in file order.
     *
     * @param  array<string, string|int>  ...$values
     */
    public static function rows(array ...$values): string
    {
        return implode("\n", array_map(static fn (array $one): string => self::row($one), $values));
    }
}
