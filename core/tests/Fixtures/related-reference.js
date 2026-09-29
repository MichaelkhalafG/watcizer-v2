// FROZEN REFERENCE — the storefront's related-products rules as they ran in the browser at 149af67
// (C-1 stage 4 moved them to core: App\Compat\CompatRelated). Extracted VERBATIM from:
//   - Frontend-next/src/Components/Product/ProductDetailClient.jsx, `related` (the scoring loop)
//   - Frontend-next/src/PageViews/Cart/Cart.jsx, `cartSuggestions`
// Only the wrapping changed: each loop now returns EVERY candidate's score (a map) so the parity test
// can compare scores rather than the product page's random tie order, and `cartList` returns the
// cart's final list. Do not "fix" anything here: a rule change goes into CompatRelated by decision,
// and this file stays the record of what the storefront did.

// ProductDetailClient.jsx — `related`, the scoring part.
export const pdpScores = (products, base) => {
  const exclude = base.id
  const currentPrice = Number(base.sale_price_after_discount || base.selling_price || 0)
  const baseGenders = Array.isArray(base.genders_en)
    ? base.genders_en
    : base.genders_en
      ? [base.genders_en]
      : []
  const baseDialIds = new Set(
    (base.dial_colors || base.dial_color || []).map((c) => c.color_id).filter(Boolean),
  )
  const out = {}
  products
    .filter((p) => p.id !== exclude)
    .filter(
      (p) =>
        p.brand_id === base.brand_id ||
        p.sub_type_id === base.sub_type_id ||
        p.category_type_id === base.category_type_id,
    )
    .forEach((p) => {
      let score = 0
      if (p.brand_id && p.brand_id === base.brand_id) score += 3
      if (p.sub_type_id && p.sub_type_id === base.sub_type_id) score += 3
      if (p.category_type_id && p.category_type_id === base.category_type_id) score += 1
      const pGenders = Array.isArray(p.genders_en)
        ? p.genders_en
        : p.genders_en
          ? [p.genders_en]
          : []
      if (baseGenders.some((g) => pGenders.includes(g))) score += 2
      if (p.watch_movement_id && p.watch_movement_id === base.watch_movement_id) score += 2
      if (p.case_shape_id && p.case_shape_id === base.case_shape_id) score += 1
      if (p.band_material_id && p.band_material_id === base.band_material_id) score += 1
      if (
        baseDialIds.size &&
        (p.dial_colors || p.dial_color || []).some((c) => baseDialIds.has(c.color_id))
      )
        score += 1
      const pPrice = Number(p.sale_price_after_discount || p.selling_price || 0)
      if (currentPrice > 0 && pPrice > 0) {
        const ratio = pPrice / currentPrice
        if (ratio >= 0.7 && ratio <= 1.3) score += 2
        else if (ratio >= 0.5 && ratio <= 1.5) score += 1
      }
      if (p.grade_id && p.grade_id === base.grade_id) score += 1
      out[p.id] = score
    })
  return out
}

// Cart.jsx — `cartSuggestions`, scoring for every candidate.
export const cartScores = (list, items) => {
  const out = {}
  if (!items.length || !list.length) return out
  const cartIds = new Set(items.map((i) => i.product_id).filter(Boolean))
  list
    .filter((p) => !cartIds.has(p.id))
    .forEach((p) => {
      let score = 0
      items.forEach((item) => {
        const cp = list.find((x) => x.id === item.product_id)
        if (!cp) return
        if (p.brand_id === cp.brand_id) score += 2
        if (p.sub_type_id === cp.sub_type_id) score += 2
        if (p.category_type_id === cp.category_type_id) score += 1
        const pg = Array.isArray(p.genders_en) ? p.genders_en : []
        const cg = Array.isArray(cp.genders_en) ? cp.genders_en : []
        if (pg.some((g) => cg.includes(g))) score += 1
        if (p.sub_type_id !== cp.sub_type_id && p.category_type_id === cp.category_type_id) score += 1
        const pp = Number(p.selling_price || 0)
        const cpp = Number(cp.selling_price || 0)
        if (pp > 0 && cpp > 0) {
          const ratio = pp / cpp
          if (ratio >= 0.5 && ratio <= 1.5) score += 1
        }
      })
      out[p.id] = score
    })
  return out
}

// Cart.jsx — `cartSuggestions`, the final list (deterministic: a stable sort, no random tie-break).
export const cartList = (list, items) => {
  if (!items.length || !list.length) return []
  const scores = cartScores(list, items)
  return list
    .filter((p) => scores[p.id] !== undefined)
    .map((p) => ({ product: p, score: scores[p.id] }))
    .filter((x) => x.score >= 2)
    .sort((a, b) => b.score - a.score)
    .slice(0, 12)
    .map((x) => x.product.id)
}
