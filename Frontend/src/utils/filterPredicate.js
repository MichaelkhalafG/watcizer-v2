// Shared product ↔ filters predicate, so the Listing results and the SideBar
// option counts always agree. `except` lets the sidebar count an option as if
// that option's OWN section were cleared (standard faceted-search behaviour).
//
// Colors are many-to-many: each product carries dial_colors / band_colors as
// arrays of { color_id, ... } (built from the pivot tables in the transform),
// so we test membership rather than a scalar id.

const PRICE_MAX = 99999999

export function passesFilters(p, f = {}, except = null) {
  const on = (key) => except !== key

  if (on('brands') && f.brands?.length && !f.brands.includes(p.brand_id)) return false
  if (on('subTypes') && f.subTypes?.length && !f.subTypes.includes(p.sub_type_id)) return false
  if (
    on('genders') &&
    f.genders?.length &&
    !f.genders.some((g) => (p.genders_en || []).includes(g))
  )
    return false
  if (on('offers') && f.offers && !(Number(p.percentage_discount) > 0)) return false
  if (on('price') && f.price) {
    const [min, max] = f.price
    const price = p.sale_price_after_discount
    if (min > 0 && price < min) return false
    if (max < PRICE_MAX && price > max) return false
  }
  if (
    on('dialColors') &&
    f.dialColors?.length &&
    !(p.dial_colors || []).some((c) => f.dialColors.includes(c.color_id))
  )
    return false
  if (
    on('bandColors') &&
    f.bandColors?.length &&
    !(p.band_colors || []).some((c) => f.bandColors.includes(c.color_id))
  )
    return false
  if (on('materials') && f.materials?.length && !f.materials.includes(p.band_material_id))
    return false
  if (on('movements') && f.movements?.length && !f.movements.includes(p.watch_movement_id))
    return false
  if (on('grades') && f.grades?.length && !f.grades.includes(p.grade_id)) return false

  return true
}
