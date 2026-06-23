import { IoIosSearch } from 'react-icons/io'
import { useContext, useState, useEffect, useRef, useMemo } from 'react'
import { MyContext } from '../../../Context/Context'
import { useUIStore } from '../../../Store/uiStore'
import { useNavigate } from 'react-router-dom'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../../utils/imageUrl'
import { productUrl } from '../../../utils/productUrl'

const MAX_RESULTS = 6

function SearchBox() {
  const { products, setFilteredProducts } = useContext(MyContext)
  const { language } = useUIStore()
  const [searchTerm, setSearchTerm] = useState('')
  const [open, setOpen] = useState(false)
  const wrapperRef = useRef(null)
  const navigate = useNavigate()
  const isRTL = language === 'ar'

  const handleSearch = () => {
    if (searchTerm.trim() !== '') {
      const filtered = products.filter(
        (product) =>
          product.product_title.toLowerCase().includes(searchTerm.toLowerCase()) ||
          product.short_description.toLowerCase().includes(searchTerm.toLowerCase()) ||
          product.brand.toLowerCase().includes(searchTerm.toLowerCase()),
      )
      setFilteredProducts(filtered)
      setOpen(false)
      navigate(`/listing?q=${encodeURIComponent(searchTerm)}`)
    }
  }

  useEffect(() => {
    const delayDebounce = setTimeout(() => {
      if (searchTerm.trim() === '') {
        setFilteredProducts(products)
      } else {
        const filtered = products.filter(
          (product) =>
            product.product_title.toLowerCase().includes(searchTerm.toLowerCase()) ||
            product.short_description.toLowerCase().includes(searchTerm.toLowerCase()) ||
            product.brand.toLowerCase().includes(searchTerm.toLowerCase()) ||
            product.search_keywords?.toLowerCase().includes(searchTerm.toLowerCase()),
        )
        setFilteredProducts(filtered)
      }
    }, 300)

    return () => clearTimeout(delayDebounce)
  }, [searchTerm, products, setFilteredProducts])

  // Local preview list for the dropdown — independent of the global
  // filteredProducts state above (which still drives the /listingsearch page).
  const matches = useMemo(() => {
    const q = searchTerm.trim().toLowerCase()
    if (q === '') return []
    return (products || []).filter(
      (product) =>
        product.product_title?.toLowerCase().includes(q) ||
        product.short_description?.toLowerCase().includes(q) ||
        product.brand?.toLowerCase().includes(q) ||
        product.search_keywords?.toLowerCase().includes(q),
    )
  }, [searchTerm, products])

  const results = matches.slice(0, MAX_RESULTS)
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
    navigate(productUrl(product))
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
                  ? `عرض كل النتائج (${matches.length})`
                  : `View all results (${matches.length})`}
              </button>
            </>
          ) : (
            <div className="wz-search-empty">
              {isRTL ? 'لا توجد نتائج' : 'No results found'}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default SearchBox
