// Watchizer's social accounts — the ONE place they are typed (2026-11-05, the client's accounts).
//
// Read by the header strip, the footer, the contact page (content/trustPages.js) and both structured-data blocks
// (the home page's Organization and lib/storesJsonLd.js, as `sameAs`). Until this date the header and the footer
// each carried their own typed copy and the site linked a Facebook page and an Instagram account that are no
// longer the shop's; TikTok was not linked at all.
//
// A module of three strings on purpose: the header and the footer load on every page, and importing these from
// trustPages.js would have put the policy pages' text beside them.
export const FACEBOOK = 'https://www.facebook.com/profile.php?id=100087711800897'
export const INSTAGRAM = 'https://www.instagram.com/watchizer_official'
export const TIKTOK = 'https://www.tiktok.com/@watchizer_eg'
