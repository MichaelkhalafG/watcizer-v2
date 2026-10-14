'use client'
import { IoIosSearch } from 'react-icons/io'
import { useState, useEffect, useRef } from 'react'
import { useListing, useCardsOf } from '../../../Hooks/queries/useListing'
import { useUIStore } from '../../../Store/uiStore'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../../utils/imageUrl'
import { productUrl } from '../../../utils/productUrl'

const MAX_RESULTS = 6
// One letter matches nearly the whole catalogue: the dropdown starts answering at two (2026-09-28).
const MIN_LETTERS = 2

// The dropdown asks core's `catalog/listing` with the typed text (C-1 stage 3) — the SAME search
// `/listing?q=` runs, so "View all results (N)" is exactly what the listing then shows. It used to
// search the whole downloaded catalogue in the browser.
function SearchBox() {
  const { language } = useUIStore()
  const [searchTerm, setSearchTerm] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const wrapperRef = useRef(null)
  const router = useRouter()
  const isRTL = language === 'ar'

  useEffect(() => {
    const t = setTimeout(() => setDebounced(searchTerm), 300)
    return () => clearTimeout(t)
  }, [searchTerm])

  // The dropdown is an answer to what is in the box NOW (2026-09-28). It used to keep the previous
  // search's results on screen while the next one loaded — the listing grid's no-flash setting — so
  // with "ro" typed it still showed the answer for "r": 799 results, effectively every watch. Here
  // there is no placeholder data, and nothing is shown while the text and the answer disagree.
  const typed = searchTerm.trim()
  const term = debounced.trim()
  const tooShort = typed.length < MIN_LETTERS
  const { data, isError } = useListing(
    { q: term, perPage: MAX_RESULTS, lang: language },
    { enabled: term.length >= MIN_LETTERS, placeholderData: undefined },
  )
  // Still typing (the debounce has not caught up), or this text's answer not back yet.
  const waiting = !tooShort && (typed !== term || (!data && !isError))
  const results = useCardsOf(!tooShort && !waiting ? data : null, language)
  const total = !tooShort && !waiting ? data?.total ?? 0 : 0

  const handleSearch = () => {
    if (searchTerm.trim() !== '') {
      setOpen(false)
      router.push(`/listing?q=${encodeURIComponent(searchTerm)}`)
    }
  }

  const showDropdown = open && searchTerm.trim() !== ''

  // Close the dropdown when clicking outside the search box.
  useEffect(() => {
    const onDocClick = (e) => {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])

  const goToProduct = (product) => {
    setOpen(false)
    router.push(productUrl(product))
  }

  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')
  const currency = isRTL ? 'ج.م' : 'EGP'

  return (
    <div className="wz-search" ref={wrapperRef}>
      <input
        type="text"
        value={searchTerm}
        onChange={(e) => setSearchTerm(e.target.value)}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
        placeholder={language === 'ar' ? 'البحث عن المنتجات' : 'Search'}
        className="wz-search-input"
      />
      <button
        type="submit"
        className="wz-search-btn"
        onClick={handleSearch}
        title="search"
        aria-label="search"
      >
        <IoIosSearch />
      </button>

      {showDropdown && (
        <div className="wz-search-results">
          {results.length > 0 ? (
            <>
              {results.map((product) => {
                const hasDiscount = Number(product.percentage_discount) > 0
                return (
                  <button
                    type="button"
                    key={product.id ?? product.product_title}
                    className="wz-search-result"
                    onClick={() => goToProduct(product)}
                  >
                    <img
                      className="wz-search-result-img"
                      src={getImageUrl(product.image) || PLACEHOLDER_IMG}
                      alt={product.product_title}
                      loading="lazy"
                      onError={handleImgError}
                    />
                    <span className="wz-search-result-info">
                      <span className="wz-search-result-name">{product.product_title}</span>
                      <span className="wz-search-result-price">
                        {hasDiscount ? (
                          <>
                            <span className="wz-search-price-sale">
                              {fmt(product.sale_price_after_discount)} {currency}
                            </span>
                            <span className="wz-search-price-old">
                              {fmt(product.selling_price)} {currency}
                            </span>
                          </>
                        ) : (
                          <span className="wz-search-price-sale">
                            {fmt(product.selling_price)} {currency}
                          </span>
                        )}
                      </span>
                    </span>
                  </button>
                )
              })}
              <button type="button" className="wz-search-viewall" onClick={handleSearch}>
                {isRTL
                  ? `عرض كل النتائج (${total})`
                  : `View all results (${total})`}
              </button>
            </>
          ) : (
            <div className="wz-search-empty">
              {tooShort
                ? isRTL
                  ? 'اكتب حرفين على الأقل'
                  : 'Type at least 2 letters'
                : waiting
                ? isRTL
                  ? 'جارٍ البحث…'
                  : 'Searching…'
                : isError
                  ? isRTL
                    ? 'تعذّر البحث الآن'
                    : "Couldn't search right now"
                  : isRTL
                    ? 'لا توجد نتائج'
                    : 'No results found'}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default SearchBox
