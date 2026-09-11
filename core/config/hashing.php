<?php

/*
|--------------------------------------------------------------------------
| Hashing — published for ONE reason: the shared `users` table is LEGACY
|--------------------------------------------------------------------------
|
| The dashboard authenticates against `users`, which the legacy Laravel 10
| application owns and which AGENTS §3 forbids this application from writing.
| Two framework defaults would have written to it silently:
|
|  1. `rehash_on_login` (default TRUE). Laravel re-hashes a password on a
|     successful login whenever the stored hash's work factor differs from the
|     configured one. Production hashes are `$2y$10$…` and the framework default
|     is cost 12, so EVERY dashboard login would have issued
|     `UPDATE users SET password = …` — a write to a legacy table, on the one
|     column whose value the legacy application must keep verifying. Off.
|
|  2. `bcrypt.rounds` (default 12). Kept at 10 to match the hashes the legacy
|     app produces, so that if a later wave ever does set a password through
|     core, both applications keep producing comparable hashes rather than a
|     silent two-tier cost.
|
| Remember-me is disabled in the login controller for the same family of
| reasons (it would write `users.remember_token`); see
| App\Http\Controllers\Manage\Auth\LoginController.
|
*/

return [

    'driver' => env('HASH_DRIVER', 'bcrypt'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 10),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],

    'rehash_on_login' => false,

];
