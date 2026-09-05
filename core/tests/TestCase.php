<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * DatabaseTransactions wraps BOTH connections: the default (clean tables) and the
     * read-only `legacy` one, so a regression in the LegacyModel guard can never write
     * permanently to a legacy table from a test.
     *
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null, 'legacy'];

    protected function setUp(): void
    {
        parent::setUp();

        // Safety guard: refuse to run against anything that is not a local database.
        // The database also holds the legacy tables; there is no test-only copy.
        $host = DB::connection()->getConfig('host');
        $host = is_string($host) ? $host : '';

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException("Tests must run against a local database; got DB host [{$host}].");
        }

        $this->withoutVite();
    }
}
