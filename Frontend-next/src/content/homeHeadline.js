// Watchizer's home headline — ONE switch for the H1, the title and the meta description (SEO item 5, 2026-10-30).
//
// `false`: the banner's H1 and the home metadata the live site showed until 2026-10-30. `true`: the proposal of
// 2026-10-29 (core/docs/deploy/SEO_CONTENT_20261029.md, option 1 — the literal non-brand query plus the store's proof
// word, and the client's facts in the meta). Switched ON by Michael's decision of 2026-10-30. Flipping it back is this
// one line; after any flip, re-run the banner fit check at the five widths (the H1 is the banner's one-line name slot)
// and rebuild.
export const USE_PROPOSED_H1 = true

const LINES = {
  current: { en: 'The Art of Timeless Precision', ar: 'فنّ الدقة الخالدة' },
  proposed: { en: 'Original Luxury Watches in Egypt', ar: 'ساعات فاخرة أصلية في مصر' },
}

/** The H1 the home banner draws, per language. */
export const STORE_LINE = LINES[USE_PROPOSED_H1 ? 'proposed' : 'current']

/** The proposed title and meta description; null while the switch is off (the page keeps its own). */
export const PROPOSED_HOME_META = USE_PROPOSED_H1
  ? {
      en: {
        title: 'Original Luxury Watches in Egypt | Watchizer',
        description:
          'Original watches from Tissot, Hugo Boss, Michael Kors and more, with a warranty of up to 3 years. Delivery to every governorate within 24 hours; free shipping over 10,000 EGP.',
      },
      ar: {
        title: 'ساعات فاخرة أصلية في مصر | Watchizer',
        description:
          'ساعات أصلية من تيسو وهوجو بوس ومايكل كورس وغيرها، بضمان حتى ٣ سنوات. توصيل لكل المحافظات خلال ٢٤ ساعة، وشحن مجاني للطلبات فوق ١٠٬٠٠٠ جنيه.',
      },
    }
  : null
