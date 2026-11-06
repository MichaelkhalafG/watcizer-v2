import localFont from 'next/font/local'

/*
 * El Messiri — the Arabic face of both shops — SELF-HOSTED (2026-11-05).
 *
 * Until this date it came through next/font/google, which downloads the files from fonts.googleapis.com at
 * BUILD time: a build that cannot reach Google fails (Hostinger, 2026-11-04: "Cannot read properties of null
 * (reading '1')" in next/font/google/loader.js). The files in this folder are the ones Google served that day
 * (El Messiri v25, a variable font, weight 400 to 700), byte for byte, so nothing a visitor sees changes; the
 * build now reads them from the repository and reaches nobody.
 *
 * Google serves one file per script and tells the browser which characters each covers (`unicode-range`).
 * next/font/local makes one family per call, so the three scripts are three families, each restricted to its
 * own characters, and app/globals.css joins them into the one variable everything already uses:
 *
 *   --font-el-messiri: <arabic>, <latin-ext>, <latin>, 'El Messiri Fallback'
 *
 * `adjustFontFallback: false` on all three: the fallback face is declared ONCE in globals.css with the numbers
 * next/font/google generated for this family (Arial, ascent 106.39%, descent 56.80%, size-adjust 95.78%), so
 * the page moves exactly as much while the font loads as it did before — not at all, in practice.
 *
 * Arabic and Latin are preloaded, as `subsets: ['arabic', 'latin']` preloaded them; Latin Extended is fetched
 * only by a page that prints one of its characters, as before.
 *
 * Licence: SIL Open Font License 1.1 — ./OFL-ElMessiri.txt (copyright 2015 The El Messiri Project Authors).
 * The option values below are literals because next/font requires them to be.
 */
const arabic = localFont({
  src: './el-messiri-arabic.woff2',
  weight: '400 700',
  style: 'normal',
  display: 'swap',
  variable: '--font-el-messiri-arabic',
  adjustFontFallback: false,
  declarations: [{ prop: 'unicode-range', value: 'U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC, U+102E0-102FB, U+10E60-10E7E, U+10EC2-10EC7, U+10EC9-10EEE, U+10EF0-10EFF, U+1EE00-1EE03, U+1EE05-1EE1F, U+1EE21-1EE22, U+1EE24, U+1EE27, U+1EE29-1EE32, U+1EE34-1EE37, U+1EE39, U+1EE3B, U+1EE42, U+1EE47, U+1EE49, U+1EE4B, U+1EE4D-1EE4F, U+1EE51-1EE52, U+1EE54, U+1EE57, U+1EE59, U+1EE5B, U+1EE5D, U+1EE5F, U+1EE61-1EE62, U+1EE64, U+1EE67-1EE6A, U+1EE6C-1EE72, U+1EE74-1EE77, U+1EE79-1EE7C, U+1EE7E, U+1EE80-1EE89, U+1EE8B-1EE9B, U+1EEA1-1EEA3, U+1EEA5-1EEA9, U+1EEAB-1EEBB, U+1EEF0-1EEF1' }],
})

const latin = localFont({
  src: './el-messiri-latin.woff2',
  weight: '400 700',
  style: 'normal',
  display: 'swap',
  variable: '--font-el-messiri-latin',
  adjustFontFallback: false,
  declarations: [{ prop: 'unicode-range', value: 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD' }],
})

const latinExt = localFont({
  src: './el-messiri-latin-ext.woff2',
  weight: '400 700',
  style: 'normal',
  display: 'swap',
  preload: false,
  variable: '--font-el-messiri-latin-ext',
  adjustFontFallback: false,
  declarations: [{ prop: 'unicode-range', value: 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF' }],
})

/** The classes <html> carries: they define the three variables globals.css joins into --font-el-messiri. */
export const elMessiriClass = `${arabic.variable} ${latin.variable} ${latinExt.variable}`
