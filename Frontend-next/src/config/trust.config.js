export const trustSignals = {
  returns:   {
    icon: '↩',
    label: '14-Day Returns', // generic label kept for the compact cart/checkout rows
    // PDP: product-type-aware, bilingual returns policy + a muted condition note.
    policy: {
      watch:   { en: '4-day return · 14-day exchange', ar: 'استرجاع خلال ٤ أيام · استبدال خلال ١٤ يوم' },
      fashion: { en: '4-day exchange or return',        ar: 'استبدال أو استرجاع خلال ٤ أيام' },
    },
    condition: { en: 'Item must be unused', ar: 'بشرط أن يكون المنتج غير مستعمل' },
  },
  guarantee: { icon: '✓', label: 'Authenticity Guarantee' },
  secure:    { icon: '🔒', label: 'Secure Checkout' },
  whatsapp:  { icon: '💬', label: 'Chat with Us', href: 'https://wa.me/201551096234' },
  payments:  ['InstaPay', 'Vodafone Cash', 'Cash on Delivery', 'Card'],
};
