<?php

namespace App\Transform;

/** Narrow a config() value to a string-keyed array without trusting its shape. */
final class Config
{
    /** @return array<string, mixed> */
    public static function stringKeyed(mixed $value): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $out[$k] = $v;
                }
            }
        }

        return $out;
    }
}
