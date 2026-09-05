<?php

use Astrotomic\Translatable\Validation\RuleFactory;

/*
| astrotomic/laravel-translatable — CLEAN_CORE_STUDY §2.1 rule 2.
| Locales are the two the platform ships; per-storefront locale lists are
| validated at the application layer against `storefronts.locales`.
| Fallback is OFF (matches the legacy app): a missing Arabic row must show
| as missing in the dashboard, not silently read the English one.
*/
return [
    'locales' => ['ar', 'en'],
    'locale_separator' => '-',
    'locale' => null,
    'use_fallback' => false,
    'use_property_fallback' => false,
    'fallback_locale' => 'en',
    'translation_model_namespace' => null,
    'translation_suffix' => 'Translation',
    'locale_key' => 'locale',
    'to_array_always_loads_translations' => true,
    'rule_factory' => [
        'format' => RuleFactory::FORMAT_ARRAY,
        'prefix' => '%',
        'suffix' => '%',
    ],
];
