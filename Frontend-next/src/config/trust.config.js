import { WHATSAPP } from '../content/contact'
import { INSPECT_SHORT } from '../content/inspection'

export const trustSignals = {
  returns:   {
    icon: '↩',
    // The compact rows — the cart drawer, the checkout strip, the cart and checkout summaries (2026-10-19, the
    // developer's change): ONE line true for every product, in the shopper's language. It said '14-Day Returns' in
    // English on Arabic pages too; the terms: watches return within 4 days or exchange within 14, fashion items
    // exchange or return within 4.
    label: { en: '14-day free returns & exchange', ar: 'إرجاع مجاني واستبدال خلال ١٤ يوماً' },
    // PDP: product-type-aware, bilingual returns policy + a muted condition note.
    policy: {
      // the policy of 2026-11-14: 14 days to return or exchange, every product, return shipping on us
      watch:   { en: '14-day free returns · 14-day exchange', ar: 'إرجاع مجاني خلال ١٤ يوماً · استبدال خلال ١٤ يوماً' },
      fashion: { en: '14-day free returns · 14-day exchange', ar: 'إرجاع مجاني خلال ١٤ يوماً · استبدال خلال ١٤ يوماً' },
    },
    condition: { en: 'Item must be unused', ar: 'بشرط أن يكون المنتج غير مستعمل' },
  },
  // every label in both languages (2026-10-19): the strip printed English on Arabic pages
  guarantee: { icon: '✓', label: { en: 'Authenticity Guarantee', ar: 'ضمان الأصالة' } },
  secure:    { icon: '🔒', label: { en: 'Secure Checkout', ar: 'دفع آمن' } },
  // inspection before payment (2026-11-14, the client): the short sentence, content/inspection.js
  inspect:   { icon: '🔍', label: INSPECT_SHORT },
  // the shop's number, typed once in content/contact.js (2026-11-14)
  whatsapp:  { icon: '💬', label: { en: 'Chat with Us', ar: 'تواصل معنا' }, href: WHATSAPP },
  // InstaPay REMOVED 2026-10-19 (the developer: "NO, it is not accepted"). The product page printed it as a way to
  // pay; the terms never listed it. The rest are the terms' own: cash on delivery, card through Paymob, and Paymob's
  // wallets.
  payments:  [
    { en: 'Vodafone Cash', ar: 'فودافون كاش' },
    { en: 'Cash on Delivery', ar: 'الدفع عند الاستلام' },
    { en: 'Card', ar: 'بطاقة' },
  ],
};
