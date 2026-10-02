import { createElement, type ReactNode } from 'react';
import Markdown from 'react-markdown';

/**
 * An article's Markdown → React ELEMENTS, safe by construction (2026-10-02, the blog editor's preview).
 *
 * THE SAME RULES as the storefront's `Frontend-next/src/lib/markdown.js` — which renders the article
 * on the shop — so what the team previews is what shoppers see. `BlogMarkdownSafeTest` renders a
 * hostile corpus through BOTH files and requires identical, harmless output: change one, change the
 * other. The rules, in short: raw HTML is shown as text (never interpreted); only ALLOWED elements
 * exist; links keep https:, http:, mailto:, tel: and site paths ("/…") only; images are shown only
 * when they are our own uploads ("/Uploads_Images/…") on our image host.
 *
 * No JSX in this file on purpose: the test loads it in plain Node.
 */
export const ALLOWED = ['p', 'br', 'strong', 'em', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a', 'img', 'hr', 'code', 'pre'];
const LINK_SCHEMES = /^(https?:|mailto:|tel:)/i;
const UPLOADS = /^\/Uploads_Images\/[A-Za-z0-9._\-/]+$/;

/** A link address we keep, or '' (the link becomes plain text). */
export function safeHref(url: unknown): string {
    const u = String(url ?? '').trim();
    if (u.startsWith('/') && !u.startsWith('//')) return u;
    if (u.startsWith('#')) return u;
    return LINK_SCHEMES.test(u) ? u : '';
}

/** An image source we show (on `assetBase`), or '' (the image is dropped). */
export function safeImg(url: unknown, assetBase: string): string {
    const u = String(url ?? '').trim();
    return UPLOADS.test(u) && !u.includes('..') ? `${assetBase.replace(/\/$/, '')}${u}` : '';
}

type Children = { children?: ReactNode };

export function MarkdownBody({
    text,
    assetBase,
    className = 'wz-article-body',
    internalHref = (h: string) => h,
}: {
    text: string;
    assetBase: string;
    className?: string;
    /** The shop localises its own links (D7); the preview leaves them as written. */
    internalHref?: (href: string) => string;
}) {
    const components = {
        h1: ({ children }: Children) => createElement('h2', null, children),
        a: ({ href, children }: Children & { href?: string }) => {
            const safe = safeHref(href);
            if (!safe) return createElement('span', null, children);
            const external = /^https?:/i.test(safe);
            return createElement('a', external ? { href: safe, target: '_blank', rel: 'noopener noreferrer nofollow' } : { href: safe.startsWith('/') ? internalHref(safe) : safe }, children);
        },
        img: ({ src, alt }: { src?: unknown; alt?: string }) => {
            const safe = safeImg(src, assetBase);
            return safe ? createElement('img', { src: safe, alt: alt ?? '', loading: 'lazy', decoding: 'async' }) : null;
        },
    };

    return createElement(
        'div',
        { className },
        createElement(
            Markdown,
            {
                allowedElements: [...ALLOWED, 'h1'],
                unwrapDisallowed: true,
                // Addresses are judged in the components above; react-markdown's own filter would blank
                // them before we see them, so they pass through unchanged here and are decided there.
                urlTransform: (url: string) => url,
                components,
            },
            text,
        ),
    );
}
