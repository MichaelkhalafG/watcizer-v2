// Catalog "card" projection (C-1 payload fix).
//
// Every listing/home/product page used to dehydrate the FULL transformed catalog
// (~2,150 products × ~80 fields × 2 languages) into its HTML — ~17 MB per page,
// which exceeds Next's in-memory response-cache maxSize and, under concurrent load,
// exhausts memory and crashes the server (native 0xC0000409).
//
// The client only needs a small slice of each product for CARDS, FILTERS, SEARCH
// and FACET COUNTS. The heavy fields (long descriptions, the gallery `images`
// array, the raw relation objects with their nested translations, and all the spec
// name-strings) are only shown on the product DETAIL page — which now fetches the
// full record by id (ProductResource) instead of reading the catalog. So we dehydrate
// a light projection and let detail load the rest on demand.
//
// This keeps EVERY field the client consumers actually read (verified against
// ProductCard, filterPredicate, ListingClient, SideBar, HomeClient, SmartSuggestions,
// CartModal, productUrl) and drops only the detail-only weight. `getServerCatalog`
// still returns the FULL catalog to the server, so buildProductSeo (JSON-LD, og:image)
// is unaffected — only the dehydrated client cache is trimmed.

// Detail-only / heavy fields dropped from the dehydrated card. Kept everywhere the
// server needs them (SEO) because SEO reads the un-projected catalog.
const DROP_KEYS = new Set([
  // heaviest: full HTML description + gallery URL array (detail uses by-id record)
  'long_description',
  'images',
  // raw relation objects (nested per-locale translations) — the computed
  // genders_en / dial_colors / band_colors below cover every client filter
  'dial_color',
  'band_color',
  'feature',
  'gender',
  // computed spec name-strings (detail-only; the *_id fields used by filters stay)
  'watch_movement', 'watch_movement_ar',
  'case_shape', 'case_shape_ar',
  'dial_display_type', 'dial_display_type_ar',
  'band_closure', 'band_closure_ar',
  'dial_glass_material', 'dial_case_material', 'band_material',
  'band_size_type', 'case_size_type', 'water_resistance_size_type',
  'band_width_size_type', 'case_thickness_size_type', 'watch_height_size_type',
  'watch_width_size_type', 'watch_length_size_type',
  // computed localized name strings not read off products (consumers use `tables`)
  'grade', 'sub_type',
  'grade_string', 'sub_type_string', 'brand_string', 'gender_string', 'feature_string',
  'model_name', 'country', 'stone',
])

export function projectCatalogCard(p) {
  if (!p || typeof p !== 'object') return p
  const out = {}
  for (const k in p) if (!DROP_KEYS.has(k)) out[k] = p[k]
  // Keep only the per-locale title (ProductCard reads translations[].product_title);
  // drop the heavy long/short descriptions carried inside each translation.
  if (Array.isArray(out.translations)) {
    out.translations = out.translations.map((t) => ({
      locale: t.locale,
      product_title: t.product_title,
    }))
  }
  // Filters test membership by color_id only — drop color names/values/hex.
  if (Array.isArray(out.dial_colors)) {
    out.dial_colors = out.dial_colors.map((c) => ({ color_id: c.color_id }))
  }
  if (Array.isArray(out.band_colors)) {
    out.band_colors = out.band_colors.map((c) => ({ color_id: c.color_id }))
  }
  return out
}

// Project a whole { ratings, productsEn, productsAr } catalog payload for dehydration.
export function projectCatalogForHydration(catalog) {
  if (!catalog) return catalog
  return {
    ratings: catalog.ratings,
    productsEn: (catalog.productsEn || []).map(projectCatalogCard),
    productsAr: (catalog.productsAr || []).map(projectCatalogCard),
  }
}

export default projectCatalogForHydration
