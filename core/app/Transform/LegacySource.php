<?php

namespace App\Transform;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The ONLY door the transform uses to read legacy tables.
 *
 * Two guards, both enforced here rather than by convention:
 *  1. Table whitelist — the 65 legacy tables of the 2026-09-05 production capture.
 *     Asking for anything else (a clean table, a typo) throws before a query runs.
 *  2. Database-level read-only session — `SET SESSION tx_read_only = 1` on the
 *     `legacy` connection, verified by reading it back. From then on MariaDB
 *     itself refuses every INSERT/UPDATE/DELETE on that session (SQLSTATE 25006),
 *     independent of any model or builder guard. (MariaDB `tx_read_only`, session
 *     scope, present since 10.0 and still the documented default-access-mode
 *     variable on the 11.x START TRANSACTION page; locally verified on 10.4.)
 */
final class LegacySource
{
    /** @var list<string> */
    public const TABLES = [
        'addresses', 'banner_bottoms', 'banner_homes', 'banner_sides', 'blogs', 'blog_images',
        'blog_translations', 'brands', 'brand_translations', 'carts', 'cart_items', 'categories',
        'category_translations', 'category_types', 'category_type_translations', 'closure_types',
        'closure_type_translations', 'colors', 'color_band_product', 'color_dial_product',
        'color_translations', 'display_types', 'display_type_translations', 'failed_jobs',
        'features', 'feature_product', 'feature_translations', 'genders', 'gender_product',
        'gender_translations', 'grades', 'grade_translations', 'jobs', 'materials',
        'material_translations', 'migrations', 'movement_types', 'movement_type_translations',
        'new_colors', 'new_sizes', 'offers', 'offer_ratings', 'offer_translations', 'orders',
        'order_items', 'password_reset_tokens', 'payment_statuses', 'personal_access_tokens',
        'products', 'product_images', 'product_ratings', 'product_translations',
        'product_variants', 'shapes', 'shape_translations', 'shipping_cities',
        'shipping_city_translations', 'size_types', 'size_type_translations', 'social_accounts',
        'sub_types', 'sub_type_translations', 'users', 'wishlists', 'wishlist_items',
    ];

    private Connection $connection;

    private bool $readOnlyEnforced = false;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? DB::connection('legacy');
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /** Make the whole legacy session read-only at the database level and prove it. */
    public function enforceReadOnly(): void
    {
        $this->connection->statement('SET SESSION tx_read_only = 1');

        $row = $this->connection->selectOne('SELECT @@session.tx_read_only AS ro');
        $value = is_object($row) && property_exists($row, 'ro') ? $row->ro : null;

        if ((int) (is_scalar($value) ? $value : 0) !== 1) {
            throw new RuntimeException('Could not put the legacy connection into read-only mode (tx_read_only stayed 0). Refusing to run.');
        }

        $this->readOnlyEnforced = true;
    }

    public function isReadOnlyEnforced(): bool
    {
        return $this->readOnlyEnforced;
    }

    /** A query builder on a whitelisted legacy table. */
    public function table(string $name): Builder
    {
        if (! in_array($name, self::TABLES, true)) {
            throw new InvalidArgumentException("[$name] is not a legacy table; the transform may only read the 65 legacy tables through LegacySource.");
        }

        return $this->connection->table($name);
    }

    public function count(string $table): int
    {
        return $this->table($table)->count();
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("[$table] is not a legacy table.");
        }

        /** @var list<string> $columns */
        $columns = $this->connection->getSchemaBuilder()->getColumnListing($table);

        return $columns;
    }

    public function databaseName(): string
    {
        return $this->connection->getDatabaseName();
    }

    public function host(): string
    {
        $host = $this->connection->getConfig('host');

        return is_string($host) ? $host : '';
    }
}
