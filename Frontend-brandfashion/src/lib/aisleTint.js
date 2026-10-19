// The family → tint helper (BRAND_FASHION_UI_SPEC §4.3, build step B2 in §20).
//
// The spec's words, quoted: "The map is a six-to-four switch on `family`, in code, with a default:
//     watch                  -> tint-watch
//     fashion                -> tint-fashion
//     bag | wallet | perfume -> tint-leather
//     electronics            -> tint-tech
//     anything else / null   -> tint-leather
// That is the whole mapping. There is no category tree to map." The storefront must never derive
// the family from the category (AGENTS §2.21(b)); `family` arrives on the product row.
//
// Tints are GROUNDS only — never text, never borders (§4.3). The values live in app/globals.css as
// --wz-tint-*; this module only resolves the NAME, so the tokens page can measure the value it
// resolves to rather than trust a copy of it here.

export const TINT_BY_FAMILY = Object.freeze({
  watch: 'tint-watch',
  fashion: 'tint-fashion',
  bag: 'tint-leather',
  wallet: 'tint-leather',
  perfume: 'tint-leather',
  electronics: 'tint-tech',
})

export const DEFAULT_TINT = 'tint-leather'

/** The six families the catalogue carries (spec §11), in the spec's order, for anything that must cover them all. */
export const FAMILIES = Object.freeze(['watch', 'bag', 'electronics', 'wallet', 'fashion', 'perfume'])

/** `family` (any case, null, unknown) → the tint TOKEN NAME, e.g. 'tint-leather'. */
export function aisleTint(family) {
  const key = typeof family === 'string' ? family.trim().toLowerCase() : ''
  return TINT_BY_FAMILY[key] ?? DEFAULT_TINT
}

/** The same, as a CSS value: `var(--wz-tint-leather)`. */
export function aisleTintVar(family) {
  return `var(--wz-${aisleTint(family)})`
}

/** Was the family matched explicitly, or did it fall to the default? The tokens page prints which. */
export function aisleTintIsDefault(family) {
  const key = typeof family === 'string' ? family.trim().toLowerCase() : ''
  return !(key in TINT_BY_FAMILY)
}
