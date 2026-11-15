// A product's REVIEW MARKUP from its approved verified reviews (SEO Phase 5, 2026-11-14) — a TWIN. Core sends
// `product.reviews` = { count, average, items } with ONLY the reviews the team approved, each from a delivered order.
// Nothing when there are none: an aggregateRating is never made up from an empty or unapproved set.
export function reviewsLd(reviews) {
  const count = Number(reviews?.count || 0)
  if (count <= 0 || !reviews?.average) return {}
  const items = (reviews.items || []).slice(0, 5)
  return {
    aggregateRating: { '@type': 'AggregateRating', ratingValue: Number(reviews.average).toFixed(1), reviewCount: count, bestRating: 5 },
    ...(items.length
      ? {
          review: items.map((r) => ({
            '@type': 'Review',
            reviewRating: { '@type': 'Rating', ratingValue: r.rating, bestRating: 5 },
            author: { '@type': 'Person', name: r.name },
            ...(r.date ? { datePublished: r.date } : {}),
            reviewBody: r.body,
          })),
        }
      : {}),
  }
}
