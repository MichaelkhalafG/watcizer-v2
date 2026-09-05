<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests run against the LOCAL copy of the shared database inside
| transactions on BOTH connections that are rolled back (DatabaseTransactions,
| see Tests\TestCase::$connectionsToTransact). RefreshDatabase and migrate:fresh
| are never used: the database also holds the legacy tables and
| DB::prohibitDestructiveCommands() is on for the whole app. The local-host
| guard and withoutVite() live in Tests\TestCase::setUp().
*/

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
