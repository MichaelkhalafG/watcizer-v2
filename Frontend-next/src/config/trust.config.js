export const trustSignals = {
  returns:   {
    icon: '↩',
    // The compact rows — the cart drawer, the checkout strip, the cart and checkout summaries (2026-10-19, the
    // developer's change): ONE line true for every product, in the shopper's language. It said '14-Day Returns' in
    // English on Arabic pages too; the terms: watches return within 4 days or exchange within 14, fashion items
    // exchange or return within 4.
    label: { en: '4-day returns · 14-day exchange on watches', ar: 'إرجاع خلال ٤ أيام · استبدال الساعات خلال ١٤ يوماً' },
    // PDP: product-type-aware, bilingual returns policy + a muted condition note.
    policy: {
      watch:   { en: '4-day return · 14-day exchange', ar: 'استرجاع خلال ٤ أيام · استبدال خلال ١٤ يوم' },
      fashion: { en: '4-day exchange or return',        ar: 'استبدال أو استرجاع خلال ٤ أيام' },
    },
    condition: { en: 'Item must be unused', ar: 'بشرط أن يكون المنتج غير مستعمل' },
  },
  // every label in both languages (2026-10-19): the strip printed English on Arabic pages
  guarantee: { icon: '✓', label: { en: 'Authenticity Guarantee', ar: 'ضمان الأصالة' } },
  secure:    { icon: '🔒', label: { en: 'Secure Checkout', ar: 'دفع آمن' } },
  whatsapp:  { icon: '💬', label: { en: 'Chat with Us', ar: 'تواصل معنا' }, href: 'https://wa.me/201551096234' },
  // InstaPay REMOVED 2026-10-19 (the developer: "NO, it is not accepted"). The product page printed it as a way to
  // pay; the terms never listed it. The rest are the terms' own: cash on delivery, card through Paymob, and Paymob's
  // wallets.
  payments:  [
    { en: 'Vodafone Cash', ar: 'فودافون كاش' },
    { en: 'Cash on Delivery', ar: 'الدفع عند الاستلام' },
    { en: 'Card', ar: 'بطاقة' },
  ],
};
