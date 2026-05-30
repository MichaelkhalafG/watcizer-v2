import { useEffect } from 'react'
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

const CACHE_DURATION = 10 * 60 * 1000
const CACHE_KEYS = {
  TABLES: 'tablesCache',
  TABLES_EXPIRATION: 'tablesCacheExpiration',
  PRODUCTS: 'productsCache',
  PRODUCTS_EXPIRATION: 'productsCacheExpiration',
  RATINGS: 'ratingsCache',
  RATINGS_EXPIRATION: 'ratingsCacheExpiration',
  IMAGES: 'imagesCache',
  IMAGES_EXPIRATION: 'imagesCacheExpiration',
}

const isCacheValid = (expirationKey) => {
  const expiration = localStorage.getItem(expirationKey)
  return expiration && new Date().getTime() < Number(expiration)
}

const getCachedData = (key) => {
  const data = localStorage.getItem(key)
  return data ? JSON.parse(data) : null
}

const useFetchTablesAndProducts = (setTables, setRatings, setProductsEn, setProductsAr) => {
  useEffect(() => {
    const fetchTablesAndProducts = async () => {
      try {
        if (
          Object.values(CACHE_KEYS).every((key) =>
            key.includes('EXPIRATION') ? isCacheValid(key) : getCachedData(key),
          )
        ) {
          // console.log("Using cached data");

          const cachedTables = getCachedData(CACHE_KEYS.TABLES)
          const cachedProducts = getCachedData(CACHE_KEYS.PRODUCTS)
          const cachedRatings = getCachedData(CACHE_KEYS.RATINGS)
          const cachedImages = getCachedData(CACHE_KEYS.IMAGES)

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
        localStorage.setItem(CACHE_KEYS.TABLES, JSON.stringify(tableData))
        localStorage.setItem(CACHE_KEYS.TABLES_EXPIRATION, new Date().getTime() + CACHE_DURATION)

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

        localStorage.setItem(CACHE_KEYS.PRODUCTS, JSON.stringify(rawProducts))
        localStorage.setItem(CACHE_KEYS.PRODUCTS_EXPIRATION, new Date().getTime() + CACHE_DURATION)

        localStorage.setItem(CACHE_KEYS.RATINGS, JSON.stringify(ratingsData))
        localStorage.setItem(CACHE_KEYS.RATINGS_EXPIRATION, new Date().getTime() + CACHE_DURATION)

        localStorage.setItem(CACHE_KEYS.IMAGES, JSON.stringify(imagesData))
        localStorage.setItem(CACHE_KEYS.IMAGES_EXPIRATION, new Date().getTime() + CACHE_DURATION)
      } catch {
        // console.error("Error fetching data:", error);
      }
    }

    fetchTablesAndProducts()
  }, [setTables, setRatings, setProductsEn, setProductsAr])
}

export default useFetchTablesAndProducts
