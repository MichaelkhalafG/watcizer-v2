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
  name: 'Brand Fashion',
  // the official white lockup (565×410, the header's and the footer's file), drawn as the file: no SVG goes through next/image
  logo: { src: '/logo-lockup-white.svg', width: 120, height: 87, svg: true },
  hub: 'BF',
  // Brand Fashion's own colours (app/globals.css): its red, ink, brass gold, violet, green and grey
  palette: ['#E2210F', '#111111', '#F0B429', '#6B3FD6', '#15713C', '#767676'],
  ogImage: '/og-spin.jpg',
  page: {
    en: {
      title: 'Spin the wheel, try your luck | Brand Fashion',
      description: 'Spin the Brand Fashion wheel once with your mobile number: free gifts, free shipping and discount codes are on it — not every spin wins.',
      alt: 'Brand Fashion — Spin the wheel',
      locale: 'en_US',
    },
    ar: {
      title: 'أدِر العجلة وجرّب حظك | Brand Fashion',
      description: 'أدِر عجلة Brand Fashion مرة واحدة برقم موبايلك: عليها هدايا مجانية وشحن مجاني وأكواد خصم — وليست كل لفّة تربح.',
      alt: 'Brand Fashion — أدِر العجلة',
      locale: 'ar_EG',
    },
  },
}
