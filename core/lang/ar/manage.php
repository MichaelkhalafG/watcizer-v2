<?php

/*
|--------------------------------------------------------------------------
| The dashboard, in Arabic — a DELIBERATE STUB
|--------------------------------------------------------------------------
|
| This file is empty on purpose and should stay that way.
|
| Every call site is `t('key', 'العربية')`: the Arabic literal is the
| FALLBACK, so Arabic renders from the components themselves. Filling this
| file would duplicate all 1,000+ strings into a second place that can drift
| from the first — and the day they disagree, nobody would know which one the
| screen is showing.
|
| The consequence, stated plainly so nobody "fixes" it: to change an Arabic
| word, edit the component. To change an English word, edit
| `lang/en/manage.php`. Arabic is the language the dashboard is written in;
| English is the translation.
|
| `LocaleSeamTest` asserts this file stays empty, so a well-meant bulk import
| of Arabic keys fails the battery rather than quietly creating the second
| source of truth.
|
*/

return [];
