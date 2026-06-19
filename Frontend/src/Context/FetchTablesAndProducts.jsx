import { useEffect, useState } from 'react'
import http from './api'

const getTranslatedName = (translations, locale, fallback) => {
  const translation = translations?.find((t) => t.locale === locale)
  return translation && translation[fallback] ? translation[fallback] : null
}

const getProductRating = (product, ratings) => {
  const productRatings = (ratings || []).filter((r) => r.product_id === product.id)
  return productRatings.length > 0
    ? productRatings.reduce((acc, r) => acc + r.rating, 0) / productRatings.length
    : null
}

const getColors = (product, name) => {
  return (
    product[name]?.map((color) => ({
      color_id: color.id,
      color_value: color.color_value,
      color_name_ar: color.translations.find((c) => c.locale === 'ar')?.color_name,
      color_name_en: color.translations.find((c) => c.locale === 'en')?.color_name,
    })) || []
  )
}

const transformProductData = (products, tables, ratings, images, locale) => {
  if (!Array.isArray(products)) return []

  return products
    .map((product) => {
      const getCategoryData = (table, id, key) =>
        getTranslatedName(
          tables[table]?.find((item) => item.id === id)?.translations || [],
          locale,
          key,
        )

      return {
        ...product,
        category_type: getCategoryData(
          'categoryTypes',
          product.category_type_id,
          'category_type_name',
        ),
        brand: getCategoryData('brands', product.brand_id, 'brand_name'),
        grade: getCategoryData('grades', product.grade_id, 'grade_name'),
        sub_type: getCategoryData('subTypes', product.sub_type_id, 'sub_type_name'),
        dial_colors: getColors(product, 'dial_color'),
        band_colors: getColors(product, 'band_color'),
        band_closure: getCategoryData('closureTypes', product.band_closure_id, 'closure_type_name'),
        case_size_type: getCategoryData('sizeTypes', product.case_size_type_id, 'size_type_name'),
        dial_display_type: getCategoryData(
          'displayTypes',
          product.dial_display_type_id,
          'display_type_name',
        ),
        case_shape: getCategoryData('shapes', product.case_shape_id, 'shape_name'),
        watch_movement: getCategoryData(
          'movementTypes',
          product.watch_movement_id,
          'movement_type_name',
        ),
        dial_glass_material: getCategoryData(
          'materials',
          product.dial_glass_material_id,
          'material_name',
        ),
        dial_case_material: getCategoryData(
          'materials',
          product.dial_case_material_id,
          'material_name',
        ),
        band_size_type: getCategoryData('sizeTypes', product.band_size_type_id, 'size_type_name'),
        water_resistance_size_type: getCategoryData(
          'sizeTypes',
          product.water_resistance_size_type_id,
          'size_type_name',
        ),
        band_width_size_type: getCategoryData(
          'sizeTypes',
          product.band_width_size_type_id,
          'size_type_name',
        ),
        case_thickness_size_type: getCategoryData(
          'sizeTypes',
          product.case_thickness_size_type_id,
          'size_type_name',
        ),
        watch_height_size_type: getCategoryData(
          'sizeTypes',
          product.watch_height_size_type_id,
          'size_type_name',
        ),
        watch_width_size_type: getCategoryData(
          'sizeTypes',
          product.watch_width_size_type_id,
          'size_type_name',
        ),
        band_material: getCategoryData('materials', product.band_material_id, 'material_name'),
        watch_length_size_type: getCategoryData(
          'sizeTypes',
          product.watch_length_size_type_id,
          'size_type_name',
        ),
        rating: getProductRating(product, ratings),
        images: (images || [])
          .filter((img) => img.product_id === product.id)
          .map(
            (img) => `${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Product_image/${img.image}`,
          ),
        features: product.feature.map((f) =>
          getTranslatedName(f.translations || [], locale, 'feature_name'),
        ),
        gender: product.gender.map((g) =>
          getTranslatedName(g.translations || [], locale, 'gender_name'),
        ),
        band_length: product.band_length,
        image: `${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Product/${product.image}`,
        product_title: getTranslatedName(product.translations || [], locale, 'product_title'),
        name: getTranslatedName(product.translations || [], 'en', 'product_title'),
        model_name: getTranslatedName(product.translations || [], locale, 'model_name'),
        country: getTranslatedName(product.translations || [], locale, 'country'),
        stone: getTranslatedName(product.translations || [], locale, 'stone'),
        stock: product.stock ?? 0,
        market_stock: product.market_stock ?? 0,
        search_keywords: product.search_keywords,
        warranty_years: parseInt(product.warranty_years),
        interchangeable_dial: product.interchangeable_dial,
        interchangeable_strap: product.interchangeable_strap,
        purchase_price: product.purchase_price,
        percentage_discount: product.percentage_discount,
        sale_price_after_discount: product.sale_price_after_discount,
        selling_price: product.selling_price,
        watch_box: product.watch_box,
        active: product.active,
        watch_length: product.watch_length,
        watch_width: product.watch_width,
        watch_height: product.watch_height,
        case_thickness: product.case_thickness,
        band_width: product.band_width,
        water_resistance: product.water_resistance,
        long_description: getTranslatedName(product.translations || [], locale, 'long_description'),
        short_description: getTranslatedName(
          product.translations || [],
          locale,
          'short_description',
        ),
        created_at: product.created_at ? new Date(product.created_at) : new Date(0),
        updated_at: product.updated_at ? new Date(product.updated_at) : new Date(0),
      }
    })
    .sort((a, b) => {
      if (a.market_stock === 0 && b.market_stock !== 0) return 1
      if (b.market_stock === 0 && a.market_stock !== 0) return -1
      return new Date(b.created_at) - new Date(a.created_at)
    })
}

const CACHE_VERSION = 'v1'
const CACHE_TTL = 10 * 60 * 1000 // 10 minutes

// Versioned, self-healing localStorage cache.
// - get/set treat null and empty arrays as "no cache" (the old bug: a cached []
//   is truthy and used to pass the gate, leaving an empty catalog forever)
// - each entry carries its own expiry; expired entries are pruned on read
const cache = {
  get(key) {
    try {
      const raw = localStorage.getItem(`wz_${CACHE_VERSION}_${key}`)
      if (!raw) return null
      const { data, expiresAt } = JSON.parse(raw)
      if (Date.now() > expiresAt) {
        localStorage.removeItem(`wz_${CACHE_VERSION}_${key}`)
        return null
      }
      if (!data || (Array.isArray(data) && data.length === 0)) return null
      return data
    } catch {
      return null
    }
  },
  set(key, data) {
    try {
      if (!data || (Array.isArray(data) && data.length === 0)) return
      localStorage.setItem(
        `wz_${CACHE_VERSION}_${key}`,
        JSON.stringify({ data, expiresAt: Date.now() + CACHE_TTL }),
      )
    } catch {
      // localStorage full or blocked — fail silently
    }
  },
  clear() {
    Object.keys(localStorage)
      .filter((k) => k.startsWith(`wz_${CACHE_VERSION}_`))
      .forEach((k) => localStorage.removeItem(k))
  },
}

// One-time cleanup of keys written by the previous (buggy) cache system.
const LEGACY_KEYS = [
  'productsCache',
  'productsCacheExpiration',
  'tablesCache',
  'tablesCacheExpiration',
  'ratingsCache',
  'ratingsCacheExpiration',
  'imagesCache',
  'imagesCacheExpiration',
  'bannersCache',
  'bannersCacheExpiration',
  'offersCache',
  'offersCacheExpiration',
  'catalog_meta_v2',
]
const purgeLegacyCache = () => {
  try {
    LEGACY_KEYS.forEach((k) => localStorage.removeItem(k))
  } catch {
    // ignore
  }
}

const useFetchTablesAndProducts = (setTables, setRatings, setProductsEn, setProductsAr) => {
  const [isFetching, setIsFetching] = useState(true)

  useEffect(() => {
    purgeLegacyCache()

    const fetchAll = async () => {
      setIsFetching(true)
      try {
        // Cache hit requires the two pieces the catalog can't render without:
        // tables + a non-empty product set. Ratings/images are enrichment and
        // default to [] (transformProductData handles empty gracefully).
        const cachedTables = cache.get('tables')
        const cachedProducts = cache.get('products')

        if (cachedTables && cachedProducts) {
          const cachedRatings = cache.get('ratings') || []
          const cachedImages = cache.get('images') || []

          setTables(cachedTables)
          setRatings(cachedRatings)
          setProductsEn(
            transformProductData(cachedProducts, cachedTables, cachedRatings, cachedImages, 'en'),
          )
          setProductsAr(
            transformProductData(cachedProducts, cachedTables, cachedRatings, cachedImages, 'ar'),
          )
          return
        }

        const tableEndpoints = [
          'all_category_type',
          'all_brand',
          'all_grade',
          'all_sub_type',
          'all_color',
          'all_material',
          'all_shape',
          'all_size_type',
          'all_display_type',
          'all_closure_type',
          'all_movement_type',
        ].map((endpoint) => `/${endpoint}`)

        const tableResponses = await Promise.all(tableEndpoints.map((url) => http.get(url)))

        const tableData = {
          categoryTypes: tableResponses[0].data,
          brands: tableResponses[1].data,
          grades: tableResponses[2].data,
          subTypes: tableResponses[3].data,
          colors: tableResponses[4].data,
          materials: tableResponses[5].data,
          shapes: tableResponses[6].data,
          sizeTypes: tableResponses[7].data,
          displayTypes: tableResponses[8].data,
          closureTypes: tableResponses[9].data,
          movementTypes: tableResponses[10].data,
        }

        setTables(tableData)
        cache.set('tables', tableData)

        const [productResponse, ratingResponse, imageResponse] = await Promise.all([
          http.get('/all_product'),
          http.get('/all_product_rating'),
          http.get('/all_product_image'),
        ])

        const rawProducts = productResponse.data
        const ratingsData = ratingResponse.data
        const imagesData = imageResponse.data

        setRatings(ratingsData)
        setProductsEn(transformProductData(rawProducts, tableData, ratingsData, imagesData, 'en'))
        setProductsAr(transformProductData(rawProducts, tableData, ratingsData, imagesData, 'ar'))

        // cache.set is a no-op for empty arrays, so an empty catalog (or empty
        // ratings/images) is never persisted.
        cache.set('products', rawProducts)
        cache.set('ratings', ratingsData)
        cache.set('images', imagesData)
      } catch (err) {
        console.error('[FetchTablesAndProducts] fetch failed:', err)
      } finally {
        setIsFetching(false)
      }
    }

    fetchAll()
  }, [setTables, setRatings, setProductsEn, setProductsAr])

  return isFetching
}

export default useFetchTablesAndProducts
