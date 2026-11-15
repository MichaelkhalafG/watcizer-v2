<?php

/*
 * ── The listing pages paint in one piece, and nothing jumps (2026-09-30, developer: "a defect with a
 *    fix, not prior art") ───────────────────────────────────────────────────────────────────────────
 *
 * Measured live on a throttled phone before the fix: /listing drew a DESKTOP-shaped skeleton cut off
 * at the right edge, then a toolbar over an empty grid, then the cards; /brand/rolex drew the header
 * alone, then an empty grid, then the cards; on desktop the footer appeared under the header and was
 * shoved down when the grid arrived — CLS 0.50 live, 0.79 locally. Three causes, three rules:
 *
 *  1. No <Suspense fallback={null}> around ListingClient: the pages are dynamic, so useSearchParams()
 *     needs none, and the boundary made React send the finished 130 KB grid as a separate block
 *     swapped in later (an empty grid first).
 *  2. The desktop footer is hidden on phones by CSS, not by useMediaQuery — which is false on the
 *     server, so the footer popped in after hydration wherever the content was not there yet.
 *  3. /listing's loading skeleton is built from the listing's own classes, so it has the page's shape. REWRITTEN
 *     2026-11-14 (SEO Phase 1): a route-level loading.jsx IS a Suspense boundary around the whole page — the server
 *     sent the skeleton in <main> and the real grid later in a hidden <div id="S:0">, so a crawler that runs no
 *     script read 0 words there (the 2026-11-13 crawl). The skeleton is gone from both apps; rule 3 is now its absence.
 *
 * After: CLS 0.0003 (/listing) and 0.0025 (/brand/rolex) on desktop; both paint whole on a phone.
 */

function listingSource(string $relative): string
{
    $path = base_path('../Frontend-next/'.$relative);
    expect(is_file($path))->toBeTrue("missing storefront file {$relative}");

    return str_replace("\r\n", "\n", (string) file_get_contents($path));
}

it('renders the listing inline, not behind a Suspense boundary that empties the grid first', function () {
    foreach (['app/(main)/listing/page.jsx', 'src/lib/facetListing.jsx'] as $file) {
        expect(preg_match('/<Suspense\b[^>]*>\s*<ListingClient\b/', listingSource($file)))
            ->toBe(0, "{$file} wraps ListingClient in <Suspense> again");
    }
});

it('puts the desktop footer in the server HTML and hides it on phones with CSS', function () {
    $footer = listingSource('app/(main)/desktop-footer.jsx');
    expect(str_contains($footer, 'useMediaQuery('))->toBeFalse('the footer is gated by useMediaQuery again (false on the server → it pops in)')
        ->and(str_contains($footer, 'wz-desktop-footer'))->toBeTrue();
    expect(preg_match('/@media \(max-width: 767\.98px\)\s*\{\s*\.wz-desktop-footer\s*\{\s*display: none;/', listingSource('src/Components/Footer/Footer.css')))
        ->toBe(1, 'Footer.css must hide .wz-desktop-footer below 768 px');
});

it('has no route-level loading skeleton for the listing pages, in either app — it would send the grid out of order', function () {
    foreach (['Frontend-next', 'Frontend-brandfashion'] as $app) {
        foreach (['listing', 'brand/[brand]', 'category/[category]', 'subtypes/[subtype]', 'grade/[grade]'] as $route) {
            expect(is_file(base_path("../{$app}/app/(main)/{$route}/loading.jsx")))
                ->toBeFalse("{$app}/app/(main)/{$route}/loading.jsx is back: the page would stream its grid in a hidden block");
        }
    }
});
