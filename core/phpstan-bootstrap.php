<?php

/**
 * Raise the memory limit for PHPStan itself.
 *
 * PHPStan has no config key for this — it honours `php.ini` or the `--memory-limit` flag — so every
 * run in this repo's notes carried `--memory-limit=1G` by hand. A run WITHOUT the flag then died at
 * the default limit and looked like a broken codebase rather than a broken command, which is a
 * false failure waiting for whoever types the obvious thing.
 *
 * `bootstrapFiles` is loaded before analysis begins, so raising it here makes the plain
 * `vendor/bin/phpstan analyse` correct. An explicit `--memory-limit` still wins.
 */
if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '1G');
}
