<?php

namespace App\Transform;

/**
 * Product family derivation (CLEAN_CORE_STUDY §2.2 / §2.9.2 step 6), configured in
 * config/transform.php `family`. Shared by step 6 (writes it), step 8/12 (branch on
 * it) and audit A-10 (reports products whose columns disagree with it).
 */
final class FamilyResolver
{
    /** @var list<string> */
    private array $watchTypeNames;

    /** @var array<string, string> */
    private array $extraPrefixes;

    /** @var array<string, string> */
    private array $subTypeNames;

    private string $default;

    /** @param  array<string, mixed>  $config  config('transform.family') */
    public function __construct(array $config)
    {
        $this->watchTypeNames = array_values(array_map(fn (mixed $v): string => mb_strtolower(is_string($v) ? $v : ''), is_array($config['watch_category_type_names'] ?? null) ? $config['watch_category_type_names'] : ['watches']));
        $this->extraPrefixes = self::stringMap($config['extra_attribute_prefixes'] ?? null);
        $this->subTypeNames = self::stringMap($config['sub_type_names'] ?? null, lowerKeys: true);
        $default = $config['default'] ?? 'fashion';
        $this->default = is_string($default) ? $default : 'fashion';
    }

    /**
     * @param  string  $categoryTypeEn  EN name of the legacy category type ('' if none)
     * @param  string|null  $extraAttributes  raw legacy JSON
     * @param  string  $subTypeEn  EN name of the legacy sub type ('' if none)
     */
    public function resolve(string $categoryTypeEn, ?string $extraAttributes, string $subTypeEn): string
    {
        if (in_array(mb_strtolower(trim($categoryTypeEn)), $this->watchTypeNames, true)) {
            return 'watch';
        }

        foreach (self::jsonKeys($extraAttributes) as $key) {
            foreach ($this->extraPrefixes as $prefix => $family) {
                if (str_starts_with($key, $prefix)) {
                    return $family;
                }
            }
        }

        $sub = mb_strtolower(trim($subTypeEn));
        if ($sub !== '' && isset($this->subTypeNames[$sub])) {
            return $this->subTypeNames[$sub];
        }

        return $this->default;
    }

    /**
     * Top-level keys of a legacy extra_attributes JSON object; empty for null/empty/invalid/non-object.
     *
     * @return list<string>
     */
    public static function jsonKeys(?string $json): array
    {
        if ($json === null || trim($json) === '' || in_array(trim($json), ['[]', '{}', 'null'], true)) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($decoded) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value, bool $lowerKeys = false): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($v)) {
                    $key = (string) $k;
                    $out[$lowerKeys ? mb_strtolower($key) : $key] = $v;
                }
            }
        }

        return $out;
    }
}
