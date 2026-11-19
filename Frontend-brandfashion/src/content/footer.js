import { TRUST_PAGES } from './trustPages'
import OffersFootLink from '../Components/Footer/OffersFootLink'

// BRAND FASHION'S PART OF THE FOOTER (2026-11-19). SPLIT: each shop has its own; the layout is the twin
// src/Components/Footer/SiteFooter.jsx. The logo, the tagline, the Shop column and the social accounts.
const SOCIAL = ['whatsapp', 'instagram', 'facebook', 'tiktok']

export const FOOTER = {
  name: 'Brand Fashion',
  // an SVG goes as the file, never through next/image
  logo: { src: '/logo-lockup-white.svg', width: 96, height: 70, next: false },
  // the shop's own line (the copied Watchizer one described a watch shop, so it had none until 2026-11-19)
  tagline: {
    en: 'Watches, fashion and accessories, delivered across Egypt',
    ar: 'ساعات وأزياء وإكسسوارات، توصيل لكل مصر',
  },
  shop: [
    { href: '/listing?sort=newest', en: 'New this week', ar: 'جديد هذا الأسبوع' },
    { href: '/listing?sale=1', en: 'Sale', ar: 'تخفيضات' },
    { href: '/listing', en: 'All products', ar: 'كل المنتجات' },
  ],
  // Offers only while an offer runs (as the old footer did)
  ShopExtra: OffersFootLink,
  // the contact page's own cards (src/content/trustPages.js): the one place the accounts are typed
  social: (lang) => (TRUST_PAGES.contact?.[lang]?.cards || []).filter((c) => SOCIAL.includes(c.icon)).map((c) => ({ href: c.href, label: c.label, icon: c.icon })),
}
