import SideBar from '../../Components/SideBar/SideBar'
import './Listing.css'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import { buildListingParams, parseListingParams, paramsKey } from '../../utils/listingParams'
import { passesFilters } from '../../utils/filterPredicate'
import ProductCard from '../../Components/Product/ProductCard'
import {
  FormControl,
  useMediaQuery,
  Drawer,
  InputLabel,
  MenuItem,
  Select,
  Button,
} from '@mui/material'
import { useContext, useState, useEffect, useMemo, useRef } from 'react'
import { BsFillGrid3X3GapFill } from 'react-icons/bs'
import { IoGrid } from 'react-icons/io5'
import { useParams, useSearchParams, useLocation } from 'react-router-dom'
import Pagination from '@mui/material/Pagination'

function Listing() {
  const { tables, watches, fashion, products } = useContext(MyContext)
  const { language, currentPage, setCurrentPage, filters, setFilters } = useUIStore()
  const isDesktop = useMediaQuery('(min-width:768px)')
  const [filteredProducts, setFilteredProducts] = useState([])
  const [displayedProducts, setDisplayedProducts] = useState([])
  const [shownum, setShownum] = useState(10)
  const { suptype, brand, category } = useParams()
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()
  // URL is the shareable source of truth on /listing; parse it (resolving slugs
  // → ids via `tables`) on each searchParams/tables change.
  const parsed = useMemo(() => parseListingParams(searchParams, tables), [searchParams, tables])
  const onListing = location.pathname === '/listing'
  // Slug↔id resolution needs the tables (loaded async). Gate the URL sync until
  // they're ready so slug URLs aren't dropped on the first render.
  const tablesReady = !!(tables && Object.keys(tables).length)
  const [colselected, setColselected] = useState('col-md-3 col-6')
  const [open, setOpen] = useState(false)

  const toggleDrawer = (newOpen) => {
    return () => {
      setOpen(newOpen)
    }
  }

  useEffect(() => {
    if (
      filters &&
      filters.categories.length === 0 &&
      filters.brands.length === 0 &&
      filters.subTypes.length === 0
    ) {
      const brandObj = tables?.brands?.find((bran) => bran.brand_name === brand)
      const subTypeObj = tables?.subTypes?.find((subty) => subty.sub_type_name === suptype)
      const categoryObj = tables?.categoryTypes?.find((cat) => cat.category_type_name === category)

      let updatedFilters = { ...filters, price: [0, 99999999] }

      if (brandObj) {
        updatedFilters.brands = [brandObj.id]
      }

      if (subTypeObj) {
        updatedFilters.subTypes = [subTypeObj.id]
      }

      if (categoryObj) {
        updatedFilters.categories = [categoryObj.id]
      }

      // Handle case where both suptype and brand are present in the route
      if (suptype && brand) {
        if (subTypeObj && brandObj) {
          updatedFilters = {
            ...filters,
            brands: [brandObj.id],
            subTypes: [subTypeObj.id],
            price: [0, 99999999],
          }
        }
      }

      if (brandObj || subTypeObj || categoryObj) {
        setFilters(updatedFilters)
      }
    }
  }, [filters, category, suptype, brand, tables, setFilters])

  // Guards the store→URL effect from echoing a store change that was itself
  // caused by the URL→store effect (which would otherwise wipe the URL on mount).
  const skipStoreToUrl = useRef(false)

  // ── URL → store: mirror the /listing query params into the store so the
  // predicate (which reads the store) and the sidebar checkboxes both reflect
  // the URL. Runs on first load, refresh, nav, and browser back/forward. ──
  useEffect(() => {
    if (!onListing || !tablesReady) return
    skipStoreToUrl.current = true // this store change came FROM the URL — don't echo back
    setFilters(parsed.filters)
    setCurrentPage(parsed.page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams, onListing, tablesReady])

  // ── store → URL: when the store changes from a user action (sidebar,
  // pagination, MegaMenu), push it back into the URL so it stays shareable /
  // refresh-safe. The ref guard skips changes that originated from the URL. ──
  useEffect(() => {
    if (!onListing || !tablesReady) return
    if (skipStoreToUrl.current) {
      skipStoreToUrl.current = false
      return
    }
    const target = buildListingParams(
      filters,
      {
        q: parsed.q,
        sort: parsed.sort,
        page: currentPage,
      },
      tables,
    )
    if (paramsKey(target) !== paramsKey(searchParams)) {
      setSearchParams(target, { replace: false })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, currentPage, onListing, tablesReady])

  const isRTL = language === 'ar'
  useEffect(() => {
    if (!products?.length && !watches?.length && !fashion?.length) return

    let thelisttofilter = products

    if (filters.categories.length > 0) {
      let filterscat = filters.categories
        .map((cat) => {
          let category = tables.categoryTypes?.find((item) => item.id === cat)
          return category?.translations?.find((t) => t.locale === language)?.category_type_name
        })
        .filter(Boolean)

      if (filterscat.length === 1) {
        if (filterscat[0] === 'Watches') {
          thelisttofilter = watches
        } else if (filterscat[0] === 'Fashion') {
          thelisttofilter = fashion
        }
      } else {
        thelisttofilter = products
      }
    }
    // All filter dimensions (brands, subTypes, genders, offers, price, dial/band
    // colors, material, movement, grade) via the shared predicate so the listing
    // and the sidebar option counts stay in agreement.
    let filtered = thelisttofilter.filter((product) => passesFilters(product, filters))

    // Free-text search via the ?q= URL param (consolidates /listingsearch).
    const q = parsed.q.trim().toLowerCase()
    if (q) {
      filtered = filtered.filter(
        (p) =>
          p.product_title?.toLowerCase().includes(q) ||
          p.short_description?.toLowerCase().includes(q) ||
          p.brand?.toLowerCase().includes(q) ||
          p.search_keywords?.toLowerCase().includes(q),
      )
    }

    // Optional sort via the ?sort= URL param.
    if (parsed.sort === 'price_asc') {
      filtered = [...filtered].sort(
        (a, b) => a.sale_price_after_discount - b.sale_price_after_discount,
      )
    } else if (parsed.sort === 'price_desc') {
      filtered = [...filtered].sort(
        (a, b) => b.sale_price_after_discount - a.sale_price_after_discount,
      )
    } else if (parsed.sort === 'newest') {
      filtered = [...filtered].sort((a, b) => b.id - a.id)
    }

    setFilteredProducts(filtered)
  }, [filters, products, watches, fashion, tables, language, parsed])

  const handleChange = (event) => {
    setShownum(event.target.value)
    setCurrentPage(1)
  }
  const handlePageChange = (event, value) => {
    setCurrentPage(value)
    window.scrollTo(0, 0)
  }
  const totalPages = Math.ceil(filteredProducts.length / shownum)
  useEffect(() => {
    if (!filteredProducts) return

    setDisplayedProducts(filteredProducts.slice((currentPage - 1) * shownum, currentPage * shownum))
  }, [currentPage, filters, filteredProducts, shownum])

  return (
    <div className={`container product-listing ${isRTL ? 'rtl' : 'ltr'}`}>
      <div className="row">
        {!isDesktop ? (
          <Drawer open={open} onClose={toggleDrawer(false)}>
            <button className="btn btn-dark rounded-0" onClick={toggleDrawer(false)}>
              {language === 'ar' ? 'اغلاق الفلاتر' : 'Close Filters'}
            </button>
            <SideBar setFilters={setFilters} />
          </Drawer>
        ) : (
          <div className="col-md-3">
            <SideBar setFilters={setFilters} />
          </div>
        )}
        <div className="col-md-9 pb-md-1 pb-5 col-12">
          {filteredProducts.length === 0 ? (
            <div className="row pt-4">
              <div
                className="row justify-content-center align-items-center p-5 text-center"
                style={{ minHeight: '50vh' }}
              >
                <h2 className="text-danger fw-bold col-12">
                  {isRTL ? 'لا توجد منتجات' : 'No Products Found'}
                </h2>
                <Button
                  variant="contained"
                  className="rounded-pill bg-most-used text-light col-12 col-md-4 py-3 fw-bold"
                  onClick={() =>
                    setFilters({
                      categories: [],
                      brands: [],
                      subTypes: [],
                      genders: [],
                      offers: false,
                      price: [0, 99999999],
                      dialColors: [],
                      bandColors: [],
                      materials: [],
                      movements: [],
                      grades: [],
                    })
                  }
                >
                  {isRTL ? 'إعادة تعيين الفلاتر' : 'Reset Filters'}
                </Button>
              </div>
            </div>
          ) : (
            <div className={`row ${isDesktop ? 'pt-4' : ''}`}>
              <div className="col-12 px-4 bg-2 rounded-3 p-2">
                <div className="row">
                  <div className="col-md-10 col-8 d-flex align-items-center">
                    <button
                      className="color-most-used btn px-1"
                      onClick={() => {
                        setColselected('col-md-3 col-6')
                      }}
                    >
                      <BsFillGrid3X3GapFill className="fs-3" />
                    </button>
                    <button
                      className="color-most-used btn px-1"
                      onClick={() => {
                        setColselected('col-md-4 col-12')
                      }}
                    >
                      <IoGrid className="fs-3" />
                    </button>
                  </div>
                  <div className="col-md-2 col-4 d-flex justify-content-end">
                    <FormControl size="small" className="text-light">
                      <InputLabel id="select-label" className="text-light">
                        {isRTL ? 'عرض' : 'Show'}
                      </InputLabel>
                      <Select
                        labelId="select-label"
                        id="simple-select"
                        value={shownum}
                        onChange={handleChange}
                        className="text-light"
                        variant="outlined"
                      >
                        {[10, 20, 30, 40].map((num) => (
                          <MenuItem key={num} value={num}>
                            {num}
                          </MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                  </div>
                </div>
              </div>
              <div className="col-12 py-2">
                {!isDesktop ? (
                  <div className="row justify-content-center">
                    <button
                      className="btn btn-dark col-10"
                      onClick={() => {
                        toggleDrawer(true)()
                      }}
                    >
                      {language === 'ar' ? 'تخصيص فلاتر' : 'Set Filters'}
                    </button>
                  </div>
                ) : null}
                <div className="row">
                  {displayedProducts.map((product) => (
                    <div key={product.id} className={`p-2 ${colselected}`}>
                      <ProductCard product={product} />
                    </div>
                  ))}
                </div>
                <div className="d-flex justify-content-center mb-md-0 mb-5 mt-4">
                  <Pagination
                    count={totalPages}
                    page={currentPage}
                    onChange={handlePageChange}
                    color="primary"
                  />
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default Listing
