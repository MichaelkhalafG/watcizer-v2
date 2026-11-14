/*
 * THE WHEEL'S BRAND (2026-11-14) — NOT A TWIN: each shop's own copy, like src/content/campaignPages.js.
 *
 * Every file of "Spin the wheel" is a twin (TWINS.txt): the page, the wheel, the floating button, the rules, the route.
 * What differs between the shops is only what is here: the shop's NAME (the invite message, the page's title, the logo's
 * alt), the MARK /spin draws at the top (wheel.css draws it white on the violet sky), the letters in the wheel's HUB, the
 * PALETTE the floating button falls back to when the campaigns answer carries no segment colours (the segments
 * themselves are the shop's own prizes and colours, authored on the dashboard), the link preview and the page's words.
 */
export const WHEEL_BRAND = {
  name: 'Watchizer',
  // the mark at its own proportions (1265×851); a raster goes through next/image
  logo: { src: '/logo.webp', width: 120, height: 81, svg: false },
  hub: 'W',
  // the client's seven wheel colours
  palette: ['#0F9D6E', '#1F4FD8', '#6B7280', '#C8102E', '#7B3FE4', '#E0457B', '#F28C28'],
  ogImage: '/og-spin.jpg',
  page: {
    en: {
      title: 'Spin the wheel, try your luck | Watchizer',
      description: 'Spin the Watchizer wheel once with your mobile number: free gifts, free shipping and discount codes are on it — not every spin wins.',
      alt: 'Watchizer — Spin the wheel',
      locale: 'en_US',
    },
    ar: {
      title: 'أدِر العجلة وجرّب حظك | Watchizer',
      description: 'أدِر عجلة Watchizer مرة واحدة برقم موبايلك: عليها هدايا مجانية وشحن مجاني وأكواد خصم — وليست كل لفّة تربح.',
      alt: 'Watchizer — أدِر العجلة',
      locale: 'ar_EG',
    },
  },
}
