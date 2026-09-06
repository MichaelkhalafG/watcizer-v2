<?php

use App\Transform\Steps\Step16SubTypes;

/*
 * 🟡-1 (review 2026-09-07): pins must decide on the FIRST run. The plan is a pure function of
 * legacy facts + config, with no clean-table state, so there is no "second pass" to converge.
 */

$pairs = [1 => [2 => 111, 4 => 7], 2 => [16 => 2, 17 => 5, 18 => 212, 21 => 2, 22 => 2]];   // the real data
$subTypes = range(1, 27);

it('places every pinned orphan under its pinned parent on the first pass', function () use ($pairs, $subTypes) {
    $pins = [1 => 1, 3 => 1, 5 => 1, 15 => 2, 19 => 2];
    $plan = Step16SubTypes::plan($pairs, $subTypes, $pins);

    $byPair = [];
    foreach ($plan as $d) {
        $byPair[$d['sub']] = $d;
    }

    expect($byPair[1])->toBe(['type' => 1, 'sub' => 1, 'orphan' => true, 'pinned' => true])
        ->and($byPair[3]['type'])->toBe(1)
        ->and($byPair[5]['type'])->toBe(1)
        ->and($byPair[15]['type'])->toBe(2)
        ->and($byPair[19]['type'])->toBe(2)
        ->and($byPair[2])->toBe(['type' => 1, 'sub' => 2, 'orphan' => false, 'pinned' => false])   // real pair untouched
        ->and(count($plan))->toBe(27);
});

it('falls back to the majority type only for unpinned orphans, and says so', function () use ($pairs, $subTypes) {
    $plan = Step16SubTypes::plan($pairs, $subTypes, [1 => 1]);
    $byPair = [];
    foreach ($plan as $d) {
        $byPair[$d['sub']] = $d;
    }

    expect($byPair[1]['type'])->toBe(1)->and($byPair[1]['pinned'])->toBeTrue()
        ->and($byPair[6]['type'])->toBe(2)->and($byPair[6]['pinned'])->toBeFalse();   // GMT unpinned → majority (Fashion has 5 sub types)
});

it('refuses an orphan when nothing can place it', function () {
    expect(fn () => Step16SubTypes::plan([], [1], []))->toThrow(RuntimeException::class, 'no product pairs exist');
});
