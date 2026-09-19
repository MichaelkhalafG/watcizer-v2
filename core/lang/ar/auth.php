<?php

/*
|--------------------------------------------------------------------------
| Authentication messages, in ARABIC (item 16, 2026-09-18)
|--------------------------------------------------------------------------
|
| ── The defect this file removes ─────────────────────────────────────────
|
| `LoginController` refuses a bad sign-in with `trans('auth.failed')`, and
| this file did not exist. So the translator fell through to
| `vendor/.../lang/en/auth.php` and an Arabic operator, on a right-to-left
| page where every other word is Arabic, was told:
|
|     These credentials do not match our records.
|
| Found by looking at the rendered page rather than at the source: the
| sentence is in the one place a reader sees only when something has already
| gone wrong, which is why it survived a full i18n pass. Its full stop even
| rendered at the wrong end of the line, because an English sentence inside
| an RTL container is reordered by the bidi algorithm.
|
| ── Why this is not the `manage` seam ────────────────────────────────────
|
| `lang/ar/manage.php` is a deliberate EMPTY stub, because every dashboard
| string is written `t('key', 'العربية')` and the Arabic lives at the call
| site. Laravel's own messages have no call site of ours to carry a
| fallback, so they need a real file — exactly as `lang/ar/validation.php`
| already does, and for the same reason. Two files, one rule.
|
| ── The wording ──────────────────────────────────────────────────────────
|
| `failed` covers BOTH "no such address" and "wrong password", and says so
| in those terms rather than naming one of them. That merge is deliberate
| and is the developer's own constraint: told apart, this form becomes a way
| to discover which e-mail addresses are real. The login screen prints what
| to check underneath, and says why it will not be more specific.
*/

return [

    'failed' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
    'password' => 'كلمة المرور غير صحيحة.',
    'throttle' => 'محاولات دخول كثيرة. حاول مرة أخرى بعد :seconds ثانية.',

];
