import { FACEBOOK, INSTAGRAM, TIKTOK } from './social'

// WATCHIZER'S PART OF THE FOOTER (2026-11-19). SPLIT: each shop has its own; the layout is the twin
// src/Components/Footer/SiteFooter.jsx. The logo, the tagline, the Shop column and the social accounts.
export const FOOTER = {
  name: 'Watchizer',
  // next/image (2026-10-11): reuses the header logo's bytes and loads lazily
  logo: { src: '/logo.webp', width: 130, height: 38, next: true },
  tagline: {
    en: "Egypt's premier destination for authentic luxury timepieces",
    ar: 'وجهتك الأولى للساعات الفاخرة الأصلية في مصر',
  },
  // the Shop column (until 2026-11-19 buttons that pushed the route; now real links)
  shop: [
    { href: '/category/watches', en: 'Watches', ar: 'ساعات' },
    { href: '/category/fashion', en: 'Fashion', ar: 'أزياء' },
    { href: '/offers', en: 'Offers', ar: 'العروض' },
    { href: '/listing?sort=newest', en: 'New Arrivals', ar: 'وصل حديثاً' },
    { href: '/listing', en: 'All Products', ar: 'كل المنتجات' },
  ],
  ShopExtra: null,
  social: () => [
    { href: FACEBOOK, label: 'Facebook', icon: 'facebook' },
    { href: INSTAGRAM, label: 'Instagram', icon: 'instagram' },
    { href: TIKTOK, label: 'TikTok', icon: 'tiktok' },
  ],
}
