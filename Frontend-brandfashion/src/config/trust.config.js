export const trustSignals = {
  returns:   {
    icon: '↩',
    // generic label for the compact cart drawer row — Brand Fashion's own window (2026-10-19): returns within 4 days for
    // every family (watches also exchange within 14); it said '14-Day Returns', which no product here offers
    label: { en: '4-Day Returns', ar: 'إرجاع خلال ٤ أيام' }, // both languages (2026-10-19, with Watchizer's)
    // PDP: product-type-aware, bilingual returns policy + a muted condition note.
    policy: {
      watch:   { en: '4-day return · 14-day exchange', ar: 'استرجاع خلال ٤ أيام · استبدال خلال ١٤ يوم' },
      fashion: { en: '4-day exchange or return',        ar: 'استبدال أو استرجاع خلال ٤ أيام' },
    },
    condition: { en: 'Item must be unused', ar: 'بشرط أن يكون المنتج غير مستعمل' },
  },
  // every label in both languages (2026-10-19): TrustSignals (a twin) prints the shopper's language
  guarantee: { icon: '✓', label: { en: 'Authenticity Guarantee', ar: 'ضمان الأصالة' } },
  secure:    { icon: '🔒', label: { en: 'Secure Checkout', ar: 'دفع آمن' } },
  whatsapp:  { icon: '💬', label: { en: 'Chat with Us', ar: 'تواصل معنا' }, href: 'https://wa.me/201283888490' },
  // InstaPay REMOVED 2026-10-19 (the developer: "NO, it is not accepted"). The product page printed it as a way to
  // pay; the terms never listed it. The rest are the terms' own: cash on delivery, card through Paymob, and Paymob's
  // wallets.
  payments:  [
    { en: 'Vodafone Cash', ar: 'فودافون كاش' },
    { en: 'Cash on Delivery', ar: 'الدفع عند الاستلام' },
    { en: 'Card', ar: 'بطاقة' },
  ],
};
