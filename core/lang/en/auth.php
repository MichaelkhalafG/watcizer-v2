<?php

/*
|--------------------------------------------------------------------------
| Authentication messages, in ENGLISH (item 16, 2026-09-18)
|--------------------------------------------------------------------------
|
| Published alongside `lang/ar/auth.php`, which is the one that fixes a real
| defect — see that file. This one exists so the pair is explicit rather
| than half-published: with only the Arabic file present, English would work
| by falling through to the framework's copy in `vendor/`, and the day
| somebody wanted to reword the English they would have no idea where it
| lived.
|
| The wording is Laravel's own, with one change: `failed` names BOTH halves
| instead of saying "these credentials", because the login screen's list of
| what-to-check reads better underneath a sentence that has said what the
| two things are.
*/

return [

    'failed' => 'The e-mail address or the password is incorrect.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

];
