// THE SHOP'S FOLLOWERS, ROUNDED (SEO Phase 1, 2026-11-14). A TWIN. The dashboard keeps the exact total
// (settings.public.followers, sent in v2 meta); the About page states it rounded DOWN, with a plus, so it never
// overstates: 900000 -> "900K+" / "أكثر من ٩٠٠ ألف", 1250000 -> "1.2M+" / "أكثر من ١٫٢ مليون". Null for no figure.

const AR_DIGITS = (s) => s.replace(/[0-9]/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]).replace('.', '٫')

export function followersText(n, lang) {
  if (!Number.isInteger(n) || n < 1000) return null
  const million = n >= 1000000
  const unit = million ? 1000000 : 1000
  // one decimal under ten units (1.2M), whole units above (12M, 900K) — always rounded down
  const step = n / unit < 10 ? 10 : 1
  const value = Math.floor((n / unit) * step) / step
  const shown = String(value)
  if (lang === 'ar') return `أكثر من ${AR_DIGITS(shown)} ${million ? 'مليون' : 'ألف'}`
  return `${shown}${million ? 'M' : 'K'}+`
}
