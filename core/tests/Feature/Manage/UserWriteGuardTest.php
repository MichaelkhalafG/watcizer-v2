<?php

use App\Domain\Access\UserWriteGuard;
use App\Domain\Access\UserWrites;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;
use Tests\Support\Shopper;
use Tests\Support\T;

/*
 * ── The `users` door at the QUERY level (review 🟠-7) ─────────────────────────────────────────
 *
 * `UserWrites` calls itself "the ONE lock on the shared `users` table". It was enforced in exactly
 * one place — `User::booted()` — which covers Eloquent and nothing else. These write the table
 * holding every customer account and every operator login, and the model never saw any of them:
 *
 *     DB::table('users')->update(['email' => …])
 *     DB::table('users')->insert([…])
 *     DB::statement('UPDATE users SET …')
 *     DB::table('users')->delete()
 *
 * So the guarantee was true of one access path and silently false of three. This file is the test
 * that fails if somebody opens it again — by disarming the guard, by dropping it out of
 * `AppServiceProvider`, or by adding a builder write to `app/`.
 */

it('REFUSES a builder update outside the door', function () {
    $user = Shopper::register();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['first_name' => 'Renamed']))
        ->toThrow(RuntimeException::class);

    /*
     * ── This assertion was INVERTED on 2026-09-23, and that is the point of the change ───────
     *
     * It used to read `->toBe('Renamed')`, with a comment explaining that the row really had moved
     * because `QueryExecuted` fires AFTER the statement — detection, not prevention, and the test
     * pinned the weaker truth honestly.
     *
     * `UserWriteGuard::refuse()` now registers the same check on `Connection::beforeExecuting()`,
     * which runs before the statement is sent. So the row does NOT move, and the guard is a door on
     * the path the model could never see. Rewritten rather than deleted (AGENTS §4): a deleted test
     * leaves no trace of what used to be permitted, and the next reader could not tell a guarantee
     * that was strengthened from one that was never claimed.
     */
    expect(T::str(DB::table('users')->where('id', $user->id)->value('first_name')))->toBe('Test');
});

it('REFUSES a write with NO TRANSACTION OPEN, which is the case a listener cannot cover', function () {
    /*
     * ── The test this file did not have, and the reason `refuse()` exists (review 🟠-7) ──────
     *
     * Every other case here runs inside the suite's wrapping transaction, where a throw from the
     * `QueryExecuted` listener rolls the statement back — so a guard that only DETECTS looks
     * identical to one that PREVENTS. The difference only shows with no transaction open, and the
     * review measured it: the row really changed, and the exception arrived afterwards to describe
     * it. *A guard that fires after the row changed is an alarm, not a door.*
     *
     * So this case unwinds the suite's transaction on purpose, which is the one thing the rest of
     * the suite must never do — and puts it back in a `finally`, because a failure here must not
     * leave the following assertions running against a live database.
     *
     * ── Why an EXISTING row and not a fresh one ─────────────────────────────────────────────
     *
     * Committing the wrapper commits everything written before it. A `Shopper::register()` here
     * would therefore be a real, permanent customer account — created by a test, in a database
     * with no test-only copy. Reading a row that already exists writes nothing at all, and if the
     * guard is working the whole case leaves the table byte-identical.
     */
    $subject = DB::table('users')->orderBy('id')->first(['id', 'first_name']);
    if ($subject === null) {
        Assert::markTestSkipped('this database holds no `users` row to attempt a write against.');
    }

    $id = T::int($subject->id);
    $before = T::str($subject->first_name);
    $level = DB::transactionLevel();

    try {
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        expect(DB::transactionLevel())->toBe(0);

        expect(fn () => DB::table('users')->where('id', $id)->update(['first_name' => 'NeverLands']))
            ->toThrow(RuntimeException::class);

        // THE assertion. With the listener alone this read 'NeverLands'.
        expect(T::str(DB::table('users')->where('id', $id)->value('first_name')))->toBe($before);
    } finally {
        // Restore whatever did land, through the declared door, before anything else runs.
        if (T::str(DB::table('users')->where('id', $id)->value('first_name')) !== $before) {
            UserWrites::open('dashboard.password', fn () => DB::table('users')->where('id', $id)
                ->update(['first_name' => $before]));
        }

        for ($i = 0; $i < $level; $i++) {
            DB::beginTransaction();
        }
    }
});

it('REFUSES a raw statement outside the door', function () {
    $user = Shopper::register();

    expect(fn () => DB::statement('UPDATE users SET first_name = ? WHERE id = ?', ['Raw', $user->id]))
        ->toThrow(RuntimeException::class);
});

it('REFUSES a builder insert outside the door', function () {
    expect(fn () => DB::table('users')->insert([
        'first_name' => 'Smuggled', 'last_name' => 'In',
        'email' => 'smuggled-'.uniqid().'@example.com',
        'password' => 'irrelevant', 'type' => 'User',
    ]))->toThrow(RuntimeException::class);
});

it('REFUSES a DELETE even INSIDE an open window', function () {
    /*
     * The one operation the rule is absolute on. `UserWrites::REASONS` declares no reason that
     * deletes a row, and `User::booted()` refuses a delete "with no escape hatch at all" — so the
     * query guard must refuse it too, or the two enforcement points disagree about the single
     * operation neither of them permits.
     */
    $user = Shopper::register();

    expect(fn () => UserWrites::open('customer.profile', fn () => DB::table('users')->where('id', $user->id)->delete()))
        ->toThrow(RuntimeException::class);

    // Inverted with the case above: the pre-execution refusal means the DELETE is never sent, so
    // the row is still there. The strongest form of the one rule that has no door at all.
    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
});

it('ALLOWS a write inside a declared reason, so the guard is a door and not a wall', function () {
    $user = Shopper::register();

    UserWrites::open('customer.profile', function () use ($user): void {
        DB::table('users')->where('id', $user->id)->update(['first_name' => 'Permitted']);
    });

    expect(T::str(DB::table('users')->where('id', $user->id)->value('first_name')))->toBe('Permitted');
});

it('never trips on a READ, which is what every real call site does', function () {
    $user = Shopper::register();

    // The seven `DB::table('users')` call sites in app/ are all of this shape.
    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->first(['email']))->not->toBeNull()
        ->and(DB::table('users')->exists())->toBeTrue();
});

it('is none of its business what the CORE-owned user tables do', function () {
    /*
     * `core_user_roles`, `core_user_token_epochs`, `core_revoked_tokens`: core's own tables, freely
     * writable, and their names all contain `user`. A guard matching loosely would have made the
     * dashboard's own grant screen unusable.
     */
    $user = Shopper::register();

    DB::table('core_user_token_epochs')->updateOrInsert(
        ['user_id' => $user->id],
        [
            'not_before' => now()->toDateTimeString(),
            'reason' => 'customer.password',
            'updated_at' => now()->toDateTimeString(),
        ],
    );

    expect(DB::table('core_user_token_epochs')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('reads the SQL shapes a hand-written statement really takes', function (string $sql, ?string $verb) {
    // The five shapes StockWriteGuard's own review found, asked of this guard: an unquoted name, a
    // leading comment, upper case spread over lines, a multi-table UPDATE … JOIN … SET, and an
    // alias-qualified column. Plus the near-misses that must NOT match.
    expect(UserWriteGuard::writeVerb($sql))->toBe($verb);
})->with([
    'plain update' => ['UPDATE users SET email = "x"', 'update'],
    'backquoted' => ['UPDATE `users` SET `email` = "x"', 'update'],
    'schema qualified' => ['UPDATE mydb.users SET email = "x"', 'update'],
    'leading block comment' => ['/* a note */ UPDATE users SET email = "x"', 'update'],
    'leading line comment' => ["-- a note\nUPDATE users SET email = 'x'", 'update'],
    'mixed case over lines' => ["Update\n  Users\nSet email = 'x'", 'update'],
    'alias on the column' => ['UPDATE users u SET u.email = "x" WHERE u.id = 1', 'update'],
    'multi-table join' => ['UPDATE addresses a JOIN users u ON u.id = a.user_id SET u.email = "x"', 'update'],
    'insert' => ['INSERT INTO users (email) VALUES ("x")', 'insert into'],
    'insert set' => ['INSERT INTO users SET email = "x"', 'insert into'],
    'replace' => ['REPLACE INTO users (email) VALUES ("x")', 'insert into'],
    'insert select' => ['INSERT INTO users SELECT * FROM staging_users', 'insert into'],
    'delete' => ['DELETE FROM users WHERE id = 1', 'delete'],
    'delete with alias' => ['DELETE u FROM users u JOIN addresses a ON a.user_id = u.id', 'delete'],

    // Not writes of this table.
    'select' => ['SELECT * FROM users WHERE id = 1', null],
    'select join' => ['SELECT * FROM orders o JOIN users u ON u.id = o.user_id', null],
    'a core table' => ['UPDATE core_user_roles SET role = "admin"', null],
    'another core table' => ['INSERT INTO core_user_token_epochs (user_id) VALUES (1)', null],
    'a table ending in users' => ['UPDATE staging_users SET email = "x"', null],
    'users only in the WHERE' => ['UPDATE orders SET status = "x" WHERE user_id IN (SELECT id FROM users)', null],
    'ddl' => ['ALTER TABLE users ADD COLUMN x INT', null],
]);

it('is ARMED in the running application — the test that fails if someone unhooks it', function () {
    /*
     * Everything above tests the class. This tests the WIRING, which is the part that can be
     * removed without any of the rest failing: delete the `UserWriteGuard::arm()` line from
     * `AppServiceProvider` and every test in this file still passes except this one.
     *
     * Asserted two ways, because either alone can be satisfied while the guard does nothing:
     * the listener is registered on THIS dispatcher, and an actual statement is actually refused.
     */
    expect(Event::hasListeners(QueryExecuted::class))->toBeTrue();

    $user = Shopper::register();
    expect(fn () => DB::table('users')->where('id', $user->id)->update(['last_name' => 'Unhooked']))
        ->toThrow(RuntimeException::class);

    // And it is armed unconditionally, not behind an environment check like the stock guard.
    $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
    $armLine = strpos($provider, 'UserWriteGuard::arm();');
    expect($armLine)->not->toBeFalse();

    // The stock guard's `if (! $this->app->isProduction())` block must not contain it.
    $stockBlock = strpos($provider, 'StockWriteGuard::arm();');
    expect($armLine)->toBeGreaterThan((int) $stockBlock + 30);

    /*
     * ── The DOOR is wired too, and separately (review 🟠-7) ─────────────────────────────────
     *
     * `arm()` is the net; `refuse()` is what stops the statement. Removing the `refuse()` wiring
     * leaves the listener in place, so every OTHER case in this file still passes — the three that
     * do not are the two inverted assertions above and the no-transaction case, and none of them
     * would say WHY. This says why.
     */
    expect(str_contains($provider, 'UserWriteGuard::refuse('))->toBeTrue();
    expect(DB::connection()->getConfig('name'))->not->toBeNull();

    // And it really is on the live connection, not merely mentioned in the provider: a second
    // `refuse()` on the same connection must be a no-op rather than a second callback.
    UserWriteGuard::refuse(DB::connection());
    $user2 = Shopper::register();
    expect(fn () => DB::table('users')->where('id', $user2->id)->update(['last_name' => 'Twice']))
        ->toThrow(RuntimeException::class);
    expect(T::str(DB::table('users')->where('id', $user2->id)->value('last_name')))->toBe('Shopper');
});

it('declares no reason that deletes a row, which is what makes the DELETE arm absolute', function () {
    $deleting = array_filter(
        UserWrites::REASONS,
        fn (string $writes): bool => str_contains(strtolower($writes), 'delet')
            || str_contains(strtolower($writes), 'remov') && str_contains(strtolower($writes), 'account'),
    );

    expect($deleting)->toBe([]);
});

it('the FIXTURE window is reachable from tests and from nowhere else', function () {
    /*
     * Arming the guard caught eleven tests, every one of them planting state the LEGACY app
     * created and core must merely cope with: a legacy bcrypt hash, a `remember_token` core's
     * model has turned off, a NULL password, a pre-existing customer row with a chosen id.
     *
     * None has a production analogue, so none earned an entry in `UserWrites::REASONS` — widening
     * the application's STATED permission to describe something it never does is the drift that
     * constant exists to prevent. Hence a separately named window.
     *
     * This is the test that keeps it separate. If `UserWriteGuard::fixture` ever appears under
     * `app/`, `routes/`, `database/` or `bootstrap/`, the fixture window has become a second door
     * into `users` with no declared reason behind it, and that is what fails here.
     */
    $offenders = [];
    foreach (['app', 'routes', 'database', 'bootstrap'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            // The guard declares the method, and its docblock discusses it.
            if (str_ends_with($file->getPathname(), 'UserWriteGuard.php')) {
                continue;
            }
            $code = commentFreeSource((string) file_get_contents($file->getPathname()));
            if (str_contains($code, 'UserWriteGuard::fixture')) {
                $offenders[] = str_replace(base_path(), '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('the fixture window does NOT lift the delete arm', function () {
    // A fixture has no reason to delete a `users` row either, so the one absolute rule stays
    // absolute inside the window as well as outside it.
    $user = Shopper::register();

    expect(fn () => UserWriteGuard::fixture(fn () => DB::table('users')->where('id', $user->id)->delete()))
        ->toThrow(RuntimeException::class);
});

it('finds NO builder write to users anywhere in app/, by census', function () {
    /*
     * The census the arming decision rests on: every `DB::table('users')` in `app/` is a read, so
     * arming in production cannot break a legitimate path. If someone adds a builder write, this
     * fails here — at the source — rather than as a 500 on the live site.
     *
     * Collected into an array rather than asserted per file: Pest's `toContain()` is variadic, so
     * a second argument meant as a message becomes a second needle.
     */
    $writes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        // The guard itself and the door name the table in prose and in patterns.
        if (str_contains($source, 'namespace App\Domain\Access')) {
            continue;
        }
        /*
         * COMMENTS STRIPPED FIRST. The first version of this census matched
         * `AppServiceProvider.php:110` — a line inside the docblock that EXPLAINS which builder
         * writes the guard exists to catch. A guard that fails on its own explanation is a guard
         * somebody deletes, so the source is reduced to code before it is scanned.
         */
        foreach (explode("\n", commentFreeSource($source)) as $number => $line) {
            if (! preg_match('/table\(\s*[\'"]users[\'"]\s*\)/', $line)) {
                continue;
            }
            if (preg_match('/->\s*(insert|insertGetId|insertOrIgnore|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\s*\(/', $line)) {
                $writes[] = str_replace(app_path(), 'app', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($writes)->toBe([]);
});

/**
 * The file's code with every comment blanked out, line numbers preserved.
 *
 * Blanked rather than removed, so a line number this census reports still points at the real line.
 */
function commentFreeSource(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}
