// THE WORDS OF A PRODUCT'S REVIEWS (2026-11-18) — a TWIN. The count in a sentence, in English and in Arabic (whose
// noun changes with the number: one, two, three to ten, eleven and more), and the lines the star line and the Reviews
// tab say. Latin digits in both languages, as everywhere on the shop.

export function reviewsCount(count, lang) {
  const n = Number(count) || 0
  if (lang === 'ar') {
    if (n === 1) return 'تقييم واحد'
    if (n === 2) return 'تقييمان'
    if (n >= 3 && n <= 10) return `${n} تقييمات`
    return `${n} تقييمًا`
  }
  return `${n} ${n === 1 ? 'review' : 'reviews'}`
}

export function starsWord(stars, lang) {
  const n = Number(stars) || 0
  if (lang === 'ar') {
    if (n === 1) return 'نجمة واحدة'
    if (n === 2) return 'نجمتان'
    return `${n} نجوم`
  }
  return `${n} ${n === 1 ? 'star' : 'stars'}`
}

export function averageLine(average, lang) {
  const a = Number(average || 0).toFixed(1)
  return lang === 'ar' ? `${a} من 5` : `${a} out of 5`
}

export const REVIEW_WORDS = {
  en: {
    first: 'Be the first to review',
    tab: 'Reviews',
    none: 'No reviews yet. Be the first to share your opinion of this product.',
    based: (count) => `Based on ${reviewsCount(count, 'en')}`,
    bar: (stars, count) => `${starsWord(stars, 'en')}: ${reviewsCount(count, 'en')}`,
    starsShort: (stars) => `${stars} ★`,
    verifiedNote: '"Verified buyer": the writer bought and received this product.',
    more: 'Show more reviews',
    loading: 'Loading…',
    moreFailed: 'We could not load more reviews just now.',
  },
  ar: {
    first: 'كن أول من يكتب تقييمًا',
    tab: 'التقييمات',
    none: 'لا توجد تقييمات بعد. كن أول من يشارك رأيه في هذا المنتج.',
    based: (count) => `بناءً على ${reviewsCount(count, 'ar')}`,
    bar: (stars, count) => `${starsWord(stars, 'ar')}: ${reviewsCount(count, 'ar')}`,
    starsShort: (stars) => `${stars} ★`,
    verifiedNote: '«مشتري موثّق»: كاتب التقييم اشترى هذا المنتج واستلمه.',
    more: 'عرض المزيد من التقييمات',
    loading: 'جارٍ التحميل…',
    moreFailed: 'تعذر تحميل المزيد من التقييمات الآن.',
  },
}
