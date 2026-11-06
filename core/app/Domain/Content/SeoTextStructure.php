<?php

namespace App\Domain\Content;

/**
 * A page text as the PARTS its layout draws (the developer's review, 2026-11-05, item 43 B).
 *
 * ── What is stored, and why that does not change ─────────────────────────────────────────────
 *
 * A brand or category page's text is ONE Markdown field per language (`core_seo_texts.description`). The storefront
 * lays it out from its shape: the paragraphs before the first heading are the INTRO; the first `##` section whose
 * points are all `- **Label:** value.` is "<name> at <shop>" — a paragraph and the FACTS, drawn as a grid of cells;
 * every other `##` section is a paragraph and its points; a section with `###` questions is the questions block.
 *
 * So the layout is already a function of the text. What was missing is on the dashboard: to get that layout an
 * admin had to type Markdown in exactly that shape, the catalogue's numbers included. This class is the shape,
 * written down once and in both directions:
 *
 *   parse()    Markdown → the parts (null when the text is NOT in the shape: it stays editable as Markdown)
 *   compile()  the parts → Markdown
 *
 * and `parse()` answers only when `compile(parse(text))` is the text again, character for character (after
 * trimming line ends and blank runs). That is what lets the edit page show fields instead of Markdown WITHOUT a
 * migration of the stored texts and without a new column: nothing is rewritten until somebody saves a page, and a
 * page that is saved untouched is saved as the same words. `seo:structure-check` reports every page.
 *
 * The facts are not typed: a fact LINE is whatever `SeoFacts` generates for it. The edit page only chooses which
 * ones show — a hidden fact is simply not in the text.
 *
 * @phpstan-type Fact array{label: string, line: string}
 * @phpstan-type Section array{heading: string, paragraph: string, bullets: list<string>}
 * @phpstan-type Structure array{intro: string, at: array{heading: string, paragraph: string, facts: list<Fact>}|null, sections: list<Section>, faq: array{heading: string, body: string}|null}
 */
final class SeoTextStructure
{
    private const FACT = '/^- \*\*(.+?):\*\*\s*(.*)$/u';

    /** Line ends as LF, no trailing spaces, at most one blank line in a row, no blank edge. */
    public static function normal(string $markdown): string
    {
        $lines = array_map(fn (string $line): string => rtrim($line), explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * @return Structure|null null: the text is not in the shape the fields edit (it stays a Markdown text)
     */
    public static function parse(string $markdown): ?array
    {
        $text = self::normal($markdown);
        $structure = ['intro' => '', 'at' => null, 'sections' => [], 'faq' => null];
        if ($text === '') {
            return $structure;
        }

        // the text before the first `## ` is the intro; after it, one block per `## ` heading
        $blocks = [];
        $intro = [];
        $current = null;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['heading' => trim(substr($line, 3)), 'lines' => []];
            } elseif ($current === null) {
                $intro[] = $line;
            } else {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }
        $structure['intro'] = trim(implode("\n", $intro));

        foreach ($blocks as $block) {
            $body = trim(implode("\n", $block['lines']));
            if (preg_match('/^### /m', $body) === 1) {
                if ($structure['faq'] !== null) {
                    return null;                                   // two question blocks: not the shape
                }
                $structure['faq'] = ['heading' => $block['heading'], 'body' => $body];

                continue;
            }
            if ($structure['faq'] !== null) {
                return null;                                       // a section after the questions: not the shape
            }

            // a paragraph (anything that is not a point), then the points, then nothing
            $paragraph = [];
            $bullets = [];
            foreach (explode("\n", $body) as $line) {
                if (str_starts_with($line, '- ')) {
                    $bullets[] = $line;
                } elseif ($bullets === []) {
                    $paragraph[] = $line;
                } elseif (trim($line) !== '') {
                    return null;                                   // text after the points, or a point on two lines
                }
            }
            $paragraph = trim(implode("\n", $paragraph));

            $facts = [];
            foreach ($bullets as $line) {
                if (preg_match(self::FACT, $line, $m) === 1) {
                    $facts[] = ['label' => trim($m[1]), 'line' => $line];
                }
            }
            if ($bullets !== [] && count($facts) === count($bullets) && $structure['at'] === null && $structure['sections'] === []) {
                $structure['at'] = ['heading' => $block['heading'], 'paragraph' => $paragraph, 'facts' => $facts];

                continue;
            }
            $structure['sections'][] = [
                'heading' => $block['heading'],
                'paragraph' => $paragraph,
                'bullets' => array_map(fn (string $line): string => trim(substr($line, 2)), $bullets),
            ];
        }

        return self::compile($structure) === $text ? $structure : null;
    }

    /**
     * @param  Structure  $structure
     */
    public static function compile(array $structure): string
    {
        $blocks = [];
        if (trim($structure['intro']) !== '') {
            $blocks[] = trim($structure['intro']);
        }
        if ($structure['at'] !== null) {
            $blocks[] = '## '.trim($structure['at']['heading']);
            if (trim($structure['at']['paragraph']) !== '') {
                $blocks[] = trim($structure['at']['paragraph']);
            }
            if ($structure['at']['facts'] !== []) {
                $blocks[] = implode("\n", array_map(fn (array $fact): string => rtrim($fact['line']), $structure['at']['facts']));
            }
        }
        foreach ($structure['sections'] as $section) {
            $blocks[] = '## '.trim($section['heading']);
            if (trim($section['paragraph']) !== '') {
                $blocks[] = trim($section['paragraph']);
            }
            $bullets = array_values(array_filter(array_map(fn (string $b): string => trim((string) preg_replace('/\s*\n\s*/', ' ', $b)), $section['bullets']), fn (string $b): bool => $b !== ''));
            if ($bullets !== []) {
                $blocks[] = implode("\n", array_map(fn (string $b): string => '- '.$b, $bullets));
            }
        }
        if ($structure['faq'] !== null && trim($structure['faq']['body']) !== '') {
            $blocks[] = '## '.trim($structure['faq']['heading']);
            $blocks[] = trim($structure['faq']['body']);
        }

        return self::normal(implode("\n\n", $blocks));
    }

    /**
     * The facts a page can show, as `SeoFacts` generates them now: the heading and the paragraph it would write, and
     * each fact's line. Empty when the catalogue has no numbers for the page.
     *
     * @return array{heading: string, paragraph: string, facts: list<Fact>}
     */
    public static function generated(string $section): array
    {
        $parsed = $section === '' ? null : self::parse($section);
        $at = $parsed['at'] ?? null;

        return $at ?? ['heading' => '', 'paragraph' => '', 'facts' => []];
    }

    /** A fact's value alone — its line without the point and the bold label. */
    public static function value(string $line): string
    {
        return preg_match(self::FACT, $line, $m) === 1 ? trim($m[2]) : trim($line);
    }
}
