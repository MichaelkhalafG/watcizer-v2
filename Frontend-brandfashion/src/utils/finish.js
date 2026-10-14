// A colour FINISH as a CSS background (2026-09-27): one colour, or a two-tone (or more) finish split
// into equal diagonal bands, primary first. Accepts a list of hexes or the '/'-joined string a cart
// line stores ("#C0C0C0/#1F3A5F").
export const finishBackground = (finish) => {
  const hexes = (Array.isArray(finish) ? finish : String(finish || '').split('/'))
    .map((h) => String(h).trim())
    .filter(Boolean)
  if (hexes.length === 0) return '#f0f0f0'
  if (hexes.length === 1) return hexes[0]
  const step = 100 / hexes.length
  return `linear-gradient(135deg, ${hexes.map((h, i) => `${h} ${i * step}% ${(i + 1) * step}%`).join(', ')})`
}
