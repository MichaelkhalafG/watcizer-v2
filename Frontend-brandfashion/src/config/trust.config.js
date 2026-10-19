export const trustSignals = {
  returns:   {
    icon: '↩',
    // generic label for the compact cart drawer row — Brand Fashion's own window (2026-10-19): returns within 4 days for
    // every family (watches also exchange within 14); it said '14-Day Returns', which no product here offers
    label: '4-Day Returns',
    // PDP: product-type-aware, bilingual returns policy + a muted condition note.
    policy: {
      watch:   { en: '4-day return · 14-day exchange', ar: 'استرجاع خلال ٤ أيام · استبدال خلال ١٤ يوم' },
      fashion: { en: '4-day exchange or return',        ar: 'استبدال أو استرجاع خلال ٤ أيام' },
    },
    condition: { en: 'Item must be unused', ar: 'بشرط أن يكون المنتج غير مستعمل' },
  },
  guarantee: { icon: '✓', label: 'Authenticity Guarantee' },
  secure:    { icon: '🔒', label: 'Secure Checkout' },
  whatsapp:  { icon: '💬', label: 'Chat with Us', href: 'https://wa.me/201283888490' },
  payments:  ['InstaPay', 'Vodafone Cash', 'Cash on Delivery', 'Card'],
};
