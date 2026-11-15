<?php

// i18n-exempt-file: matching data, never rendered - the Arabic heading words that mark a questions or sources section

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * A page text or an article body (Markdown) read the way the quality gate needs it (2026-11-14): its `##` sections, its
 * questions (`###` lines ending in "?" or "؟", each with its answer), every link, the links of its Sources section, and
 * how many words of PROSE it holds — headings, table rows, data blocks and the {facts} token are not prose.
 */
final class MarkdownDoc
{
    /**
     * @param  list<array{heading: string, level: int, text: string, faq: bool, sources: bool, facts: bool}>  $sections
     * @param  list<array{question: string, answer: string}>  $faq
     * @param  list<array{label: string, href: string}>  $links
     * @param  list<string>  $sourceLinks
     * @param  list<string>  $h3
     */
    private function __construct(
        public readonly string $lead,
        public readonly array $sections,
        public readonly array $faq,
        public readonly array $links,
        public readonly array $sourceLinks,
        public readonly array $h3,
        private readonly string $body,
    ) {}

    public static function parse(string $markdown): self
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $markdown)) ?: [];
        $lead = [];
        $sections = [];
        $faq = [];
        $h3 = [];
        $current = null;
        $question = null;
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/u', $line, $m) === 1 && ! str_starts_with($line, '###')) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $heading = trim($m[1]);
                $current = ['heading' => $heading, 'level' => 2, 'text' => '',
                    'faq' => preg_match('/(question|faq|أسئلة|الأسئلة|سؤال)/iu', $heading) === 1,
                    'sources' => preg_match('/^(sources|references|المصادر|مصادر)/iu', $heading) === 1, 'facts' => false];
                $question = null;

                continue;
            }
            if (preg_match('/^###\s+(.+?)\s*$/u', $line, $m) === 1) {
                $h = trim($m[1]);
                $h3[] = $h;
                if (preg_match('/[?؟]$/u', $h) === 1) {
                    $faq[] = ['question' => $h, 'answer' => ''];
                    $question = count($faq) - 1;
                    if ($current !== null) {
                        $current['faq'] = true;
                    }
                } else {
                    $question = null;
                }
                if ($current !== null) {
                    $current['text'] .= "\n";
                }

                continue;
            }
            if ($question !== null) {
                $faq[$question]['answer'] .= $line."\n";
            }
            if ($current === null) {
                $lead[] = $line;
            } else {
                $current['text'] .= $line."\n";
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }
        // the generated "in numbers" section (SeoFacts, `{facts}`): its lines are "- **Label:** value"
        foreach ($sections as $i => $sec) {
            $sections[$i]['facts'] = preg_match_all('/^\s*-\s*\*\*[^*\n]+:\*\*/mu', $sec['text']) >= 2;
        }
        $faq = array_map(fn (array $q): array => ['question' => (string) $q['question'], 'answer' => trim((string) $q['answer'])], $faq);
        $links = [];
        // links only — an image (`![alt](src)`) is not a link to a page (2026-11-14: the article gate read every in-text
        // photo as "a link to a page the shop does not have")
        preg_match_all('/(?<!!)\[([^\]]*)\]\(([^)\s]+)\)/u', $markdown, $lm, PREG_SET_ORDER);
        foreach ($lm as $m) {
            $links[] = ['label' => $m[1], 'href' => $m[2]];
        }
        $sourceLinks = [];
        foreach ($sections as $s) {
            if ($s['sources']) {
                preg_match_all('/\]\(([^)\s]+)\)|(https:\/\/\S+)/u', $s['text'], $sm, PREG_SET_ORDER);
                foreach ($sm as $m) {
                    $sourceLinks[] = ($m[1] ?? '') !== '' ? $m[1] : rtrim($m[2] ?? '', '.,)');
                }
            }
        }

        return new self(implode("\n", $lead), $sections, $faq, $links, $sourceLinks, $h3, $markdown);
    }

    /** Words of prose: the lead and the sections, without headings, table rows, data blocks, sources — and, when asked, without the questions' answers. */
    public function proseWords(bool $excludeFaq = false): int
    {
        $text = $this->lead."\n";
        foreach ($this->sections as $s) {
            if ($s['sources'] || ($excludeFaq && $s['faq'])) {
                continue;
            }
            $text .= $s['text']."\n";
        }
        $kept = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*(\||#)/u', $line) === 1) {
                continue;                                            // a table row or a heading
            }
            $kept[] = $line;
        }

        return Words::count(implode("\n", $kept));
    }

    /** The whole text. */
    public function body(): string
    {
        return $this->body;
    }

    /** The text WITHOUT the generated facts section — what the catalogue and language checks read (the facts are the catalogue's own). */
    public function withoutFacts(): string
    {
        $out = $this->lead."\n";
        foreach ($this->sections as $s) {
            if (! $s['facts']) {
                $out .= '## '.$s['heading']."\n".$s['text']."\n";
            }
        }

        return $out;
    }
}
