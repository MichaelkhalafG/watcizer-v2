// THE ORIGINAL WATCHES PAGE'S WORDS (Michael, 2026-11-14). A TWIN: each shop's name is filled in from STOREFRONT_CODE.
//
// The page lists only the watches the hide-from-search rule leaves alone — never a matched product, never the
// restricted Rolex, never a Generic watch (core's ProductListing `collection=original-watches`). So it may say what is
// true of every watch it lists, and only that: original, with its box, a warranty card and the sales invoice; the
// warranty's length on each product page; delivery in 24 hours; inspection before payment; 14-day free returns. No
// claim of being an authorized dealer or agent for any brand.
import { STOREFRONT_CODE } from '../lib/env'
import { INSPECT_SHORT } from './inspection'

const SHOP = STOREFRONT_CODE === 'brandfashion' ? { en: 'Brand Fashion', ar: 'براند فاشون' } : { en: 'Watchizer', ar: 'واتشايزر' }

export const ORIGINAL_WATCHES_PATH = '/original-watches'

export const ORIGINAL_WATCHES = {
  heading: { en: 'Original watches', ar: 'ساعات أصلية' },
  title: { en: `Original Watches in Egypt | ${SHOP.en}`, ar: `ساعات أصلية في مصر | ${SHOP.ar}` },
  meta: {
    en: `Original watches at ${SHOP.en}, each with its box, a warranty card and the sales invoice. Delivery in 24 hours, inspect before you pay.`,
    ar: `ساعات أصلية من ${SHOP.ar}، كل ساعة بعلبتها وبطاقة الضمان وفاتورة البيع. توصيل خلال ٢٤ ساعة، ومعاينة قبل الدفع.`,
  },
  // the intro under the grid (FacetIntro): Markdown, both languages
  intro: {
    en: [
      "Every watch on this page is original and comes with its box, a warranty card and a sales invoice. The length of each watch's warranty is on its product page.",
      '',
      '- Delivery to every governorate within 24 hours.',
      `- ${INSPECT_SHORT.en}.`,
      '- Free returns within 14 days, return shipping on us.',
    ].join('\n'),
    ar: [
      'كل ساعة في هذه الصفحة أصلية، وتأتي بعلبتها وبطاقة الضمان وفاتورة البيع. ومدة ضمان كل ساعة مذكورة في صفحتها.',
      '',
      '- توصيل لكل المحافظات خلال ٢٤ ساعة.',
      `- ${INSPECT_SHORT.ar}.`,
      '- إرجاع مجاني خلال ١٤ يومًا، وشحن الإرجاع علينا.',
    ].join('\n'),
  },
  crumbs: { home: { en: 'Home', ar: 'الرئيسية' }, watches: { en: 'Watches', ar: 'ساعات' } },
}
