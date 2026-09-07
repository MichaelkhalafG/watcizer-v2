<?php

namespace App\Compat\Diff;

/**
 * Sitemap diff: the document is split into `<url>` blocks keyed by `<loc>`; a block present on
 * one side only is missing_in_compat / extra_in_compat, a changed block is a value finding on
 * `url:<path>`; the prologue (everything before the first `<url>`) and the block order are
 * compared too. Paths are `url:/subtypes/diver` so DeviationRules can match by URL prefix.
 *
 * @phpstan-type Finding array{path: string, kind: string, legacy: mixed, compat: mixed}
 */
final class XmlDiff
{
    /** @return list<array{path: string, kind: string, legacy: mixed, compat: mixed}> */
    public static function compare(string $legacy, string $compat): array
    {
        $findings = [];
        [$prologueA, $blocksA, $orderA] = self::split($legacy);
        [$prologueB, $blocksB, $orderB] = self::split($compat);
        if ($prologueA !== $prologueB) {
            $findings[] = ['path' => 'prologue', 'kind' => 'value', 'legacy' => mb_substr($prologueA, 0, 120), 'compat' => mb_substr($prologueB, 0, 120)];
        }
        foreach ($blocksA as $loc => $block) {
            if (! isset($blocksB[$loc])) {
                $findings[] = ['path' => 'url:'.self::pathOf($loc), 'kind' => 'missing_in_compat', 'legacy' => $loc, 'compat' => null];
            } elseif ($blocksB[$loc] !== $block) {
                $findings[] = ['path' => 'url:'.self::pathOf($loc), 'kind' => 'value', 'legacy' => self::firstDiff($block, $blocksB[$loc]), 'compat' => self::firstDiff($blocksB[$loc], $block)];
            }
        }
        foreach ($blocksB as $loc => $block) {
            if (! isset($blocksA[$loc])) {
                $findings[] = ['path' => 'url:'.self::pathOf($loc), 'kind' => 'extra_in_compat', 'legacy' => null, 'compat' => $loc];
            }
        }
        $sharedA = array_values(array_filter($orderA, fn (string $l) => isset($blocksB[$l])));
        $sharedB = array_values(array_filter($orderB, fn (string $l) => isset($blocksA[$l])));
        if ($sharedA !== $sharedB) {
            $findings[] = ['path' => 'urls', 'kind' => 'order', 'legacy' => count($sharedA).' urls', 'compat' => 'different order'];
        }

        return $findings;
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: list<string>}
     */
    private static function split(string $xml): array
    {
        $xml = str_replace("\r\n", "\n", $xml);
        $first = strpos($xml, '<url>');
        $prologue = $first === false ? $xml : substr($xml, 0, $first);
        $blocks = [];
        $order = [];
        if (preg_match_all('#<url>.*?</url>#s', $xml, $m) > 0) {
            foreach ($m[0] as $i => $block) {
                $loc = preg_match('#<loc>(.*?)</loc>#s', $block, $lm) === 1 ? html_entity_decode($lm[1]) : "#{$i}";
                $key = $loc;
                $n = 1;
                while (isset($blocks[$key])) {                   // twins share a URL on the legacy sitemap: keep both
                    $n++;
                    $key = $loc.' #'.$n;
                }
                $blocks[$key] = $block;
                $order[] = $key;
            }
        }

        return [$prologue, $blocks, $order];
    }

    private static function pathOf(string $loc): string
    {
        $suffix = '';
        if (preg_match('/ #\d+$/', $loc, $m) === 1) {
            $suffix = $m[0];
            $loc = substr($loc, 0, -strlen($suffix));
        }
        $path = parse_url($loc, PHP_URL_PATH);

        return (is_string($path) ? $path : $loc).$suffix;
    }

    private static function firstDiff(string $a, string $b): string
    {
        $linesA = explode("\n", $a);
        $linesB = explode("\n", $b);
        foreach ($linesA as $i => $line) {
            if (($linesB[$i] ?? null) !== $line) {
                return trim($line);
            }
        }

        return '(same lines, different length)';
    }
}
