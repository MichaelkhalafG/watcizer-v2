<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The old WordPress posts' HTML → the Markdown the shop renders (2026-11-11, D-13).
 *
 * Only what the storefront's renderer allows survives (`src/lib/markdown.js` ALLOWED: p, br,
 * strong, em, h2–h4, lists, blockquote, a, img, hr): an `h1` becomes `##` (the page keeps one h1,
 * the article title), anything else is unwrapped to its text, scripts/styles/iframes are dropped.
 * WordPress stores bodies WITHOUT paragraph tags (it adds them on output, "wpautop"), so a line
 * break in the text is a paragraph here.
 *
 * Links and images are decided by the caller ({@see fromHtml()}'s two mappers): an old shop link
 * is rewritten to its NEW URL, an old upload is re-processed into our `Uploads_Images` — and either
 * mapper may answer '' to keep only the words (a link) or drop the picture (an image).
 */
final class LegacyMarkdown
{
    /** The WordPress size suffix of a derived image (`-1024x683` before the extension). */
    private const SIZE_SUFFIX = '/-\d{2,5}x\d{2,5}(?=\.[A-Za-z0-9]+$)/';

    /**
     * Every upload ORIGINAL a body refers to (img src, a link to a file), relative to `uploads/` —
     * a size variant is read back to its original, which is the file the archive must provide.
     *
     * @return list<string>
     */
    public static function uploadFiles(string $html): array
    {
        $out = [];
        if (preg_match_all('~/wp-content/uploads/([^"\'\s?#)]+\.(?:jpe?g|png|gif|webp))~i', $html, $m) > 0) {
            foreach ($m[1] as $path) {
                $out[self::original(rawurldecode($path))] = true;
            }
        }

        return array_keys($out);
    }

    /** `2024/12/bag-1024x683.jpg` → `2024/12/bag.jpg`. */
    public static function original(string $path): string
    {
        return preg_replace(self::SIZE_SUFFIX, '', $path) ?? $path;
    }

    /**
     * @param  callable(string): string  $link  an old href → the href to keep ('' = words only)
     * @param  callable(string, string): string  $image  an old src and its alt → our src ('' = drop)
     */
    public static function fromHtml(string $html, callable $link, callable $image): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('root');
        if ($root === null) {
            return '';
        }
        $md = self::children($root, $link, $image);

        // Tidy: trailing spaces off every line, never more than one blank line.
        $lines = array_map(fn (string $l): string => rtrim($l), explode("\n", $md));
        $md = implode("\n", $lines);
        $md = preg_replace("/\n{3,}/", "\n\n", $md) ?? $md;

        return trim($md);
    }

    /**
     * @param  callable(string): string  $link
     * @param  callable(string, string): string  $image
     */
    private static function children(DOMNode $node, callable $link, callable $image): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $link, $image);
        }

        return $out;
    }

    /**
     * @param  callable(string): string  $link
     * @param  callable(string, string): string  $image
     */
    private static function node(DOMNode $node, callable $link, callable $image): string
    {
        if ($node instanceof DOMText) {
            $text = str_replace("\u{00A0}", ' ', $node->wholeText);
            // A line break in WordPress's stored text is a paragraph.
            $text = preg_replace("/[ \t]*\r?\n[ \t]*/", "\n\n", $text) ?? $text;

            return self::escape(preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text);
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        $inner = fn (): string => self::children($node, $link, $image);
        $line = fn (): string => trim(preg_replace('/\s+/u', ' ', self::children($node, $link, $image)) ?? '');

        return match ($tag) {
            'script', 'style', 'iframe', 'noscript', 'form', 'button' => '',
            'h1', 'h2' => "\n\n## ".$line()."\n\n",
            'h3' => "\n\n### ".$line()."\n\n",
            'h4', 'h5', 'h6' => "\n\n#### ".$line()."\n\n",
            'p', 'div', 'section', 'article', 'figure' => "\n\n".$inner()."\n\n",
            'br' => "\n\n",
            'hr' => "\n\n---\n\n",
            'strong', 'b' => self::wrap('**', $line()),
            'em', 'i' => self::wrap('*', $line()),
            'a' => self::link($node, $line(), $link),
            'img' => self::image($node, $image),
            'ul', 'ol' => "\n\n".self::items($node, $tag === 'ol', $link, $image)."\n\n",
            'blockquote' => "\n\n".implode("\n", array_map(fn (string $l): string => '> '.$l, array_filter(explode("\n", trim($inner())), fn (string $l): bool => trim($l) !== '')))."\n\n",
            default => $inner(),
        };
    }

    /**
     * @param  callable(string): string  $link
     * @param  callable(string, string): string  $image
     */
    private static function items(DOMElement $list, bool $ordered, callable $link, callable $image): string
    {
        $lines = [];
        $n = 1;
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $text = trim(preg_replace('/\s+/u', ' ', self::children($child, $link, $image)) ?? '');
                if ($text !== '') {
                    $lines[] = ($ordered ? ($n++).'. ' : '- ').$text;
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @param callable(string): string $link */
    private static function link(DOMElement $a, string $text, callable $link): string
    {
        if ($text === '') {
            return '';
        }
        $href = $link(trim($a->getAttribute('href')));

        return $href === '' ? $text : '['.$text.']('.str_replace([' ', ')'], ['%20', '%29'], $href).')';
    }

    /** @param callable(string, string): string $image */
    private static function image(DOMElement $img, callable $image): string
    {
        $alt = trim($img->getAttribute('alt'));
        $src = $image(trim($img->getAttribute('src')), $alt);

        return $src === '' ? '' : "\n\n![".self::escape($alt).']('.$src.")\n\n";
    }

    private static function wrap(string $mark, string $text): string
    {
        return $text === '' ? '' : $mark.$text.$mark;
    }

    /** Markdown's own characters in running text stay text. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', '*', '_', '`', '[', ']'], ['\\\\', '\\*', '\\_', '\\`', '\\[', '\\]'], $text);
    }
}
