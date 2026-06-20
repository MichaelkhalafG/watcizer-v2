import { useContext, useState, useEffect, Fragment } from 'react'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import {
  Accordion,
  AccordionSummary,
  AccordionDetails,
  Checkbox,
  FormControlLabel,
  Typography,
  Slider,
} from '@mui/material'
import ExpandMoreIcon from '@mui/icons-material/ExpandMore'

function valuetext(value) {
  return `${value}`
}

function OffersSideBar({ setFilters }) {
  const { tables, sideBanners } = useContext(MyContext)
  const { language, setCurrentPage } = useUIStore()
  const isRTL = language === 'ar'
  const direction = isRTL ? 'rtl' : 'ltr'
  const [categories, setCategories] = useState([])
  const [value, setValue] = useState([0, 6000])
  const [priceRange, setPriceRange] = useState([0, 6000])
  const [selectedRating, setSelectedRating] = useState(null)
  const [selectedCategories, setSelectedCategories] = useState([])

  const handlePriceChange = (event, newValue) => {
    setValue(newValue)
    setFilters((prevFilters) => ({ ...prevFilters, price: newValue }))
  }

  const handleRatingChange = (event) => {
    const rating = event.target.value
    setSelectedRating(rating)
    setFilters((prevFilters) => ({ ...prevFilters, rating }))
  }

  const handleCheckboxChange = (id, filterType) => {
    setCurrentPage(1)
    const updateSelectedItems = (prevSelected) =>
      prevSelected.includes(id)
        ? prevSelected.filter((itemId) => itemId !== id)
        : [...prevSelected, id]

    if (filterType === 'categories') {
      setSelectedCategories((prev) => {
        const updated = updateSelectedItems(prev)
        setFilters((filters) => ({ ...filters, categories: updated }))
        return updated
      })
    }
  }
  useEffect(() => {
    setPriceRange([0, 6000])
  }, [])

  useEffect(() => {
    const extractLocalizedNames = (data, key) => {
      return data.map((item) => {
        const translation = item.translations.find((t) => t.locale === language)
        if (translation) {
          item[key] = translation[key]
        }
        return item
      })
    }

    if (tables) {
      const localizedCategories = extractLocalizedNames(
        tables.categoryTypes || [],
        'category_type_name',
      )
      setCategories(localizedCategories)
    }
  }, [tables, language])

  const renderList = (data, key, filterType) => (
    <AccordionDetails className="p-0 m-0 border-0">
      <ul className={`list-unstyled p-0 m-0 row ${isRTL ? 'text-end' : 'text-start'}`}>
        {data.map((item) => (
          <li key={item.id} className="col-6" style={{ fontSize: 'small' }}>
            <FormControlLabel
              control={
                <Checkbox
                  checked={selectedCategories.includes(item.id)}
                  onChange={() => handleCheckboxChange(item.id, filterType)}
                />
              }
              label={item[key] || `Unnamed ${key}`}
            />
          </li>
        ))}
      </ul>
    </AccordionDetails>
  )

  return (
    <div className="pt-2 container-fluid p-0" dir={direction}>
      <div className="tables-list-filter">
        <p className={`fs-6 fw-bold color-most-used p-2 pb-0 ${isRTL ? 'text-end' : 'text-start'}`}>
          {isRTL ? 'التصنيف بالنوع.' : 'FILTER BY TYPE.'}
        </p>
        <Accordion className="border-0 shadow-none fs-5 p-0">
          <AccordionSummary className="border-0" expandIcon={<ExpandMoreIcon />}>
            <Typography>{isRTL ? 'فئات' : 'Categories'}</Typography>
          </AccordionSummary>
          {renderList(categories, 'category_type_name', 'categories')}
        </Accordion>
      </div>
      <p
        className={`fs-6 fw-bold color-most-used p-4 ps-2 pb-0 ${isRTL ? 'text-end' : 'text-start'}`}
      >
        {isRTL ? 'التصنيف بالسعر.' : 'FILTER BY PRICE.'}
      </p>
      <div className="price-filter col-12 m-0 px-2 row">
        <Slider
          min={priceRange[0]}
          max={priceRange[1]}
          value={value}
          onChange={handlePriceChange}
          valueLabelDisplay="auto"
          getAriaValueText={valuetext}
        />
        <p className={`col-5 p-1 color-most-used ${isRTL ? 'text-end' : 'text-start'}`}>
          {isRTL ? 'من:' : 'from:'} <span className="fw-bold">{value[0]}</span>
        </p>
        <p className="col-2 p-1" />
        <p className={`col-5 p-1 ${isRTL ? 'text-start' : 'text-end'} color-most-used`}>
          {isRTL ? 'الى:' : 'to:'} <span className="fw-bold">{value[1]}</span>
        </p>
      </div>
      <p
        className={`fs-6 fw-bold color-most-used p-4 ps-2 pb-0 ${isRTL ? 'text-end' : 'text-start'}`}
      >
        {isRTL ? 'التصنيف بالتقييم.' : 'FILTER BY RATING.'}
      </p>
      <div className="rating-filter d-flex mb-5 px-3 justify-content-start">
        <div className="rating">
          {[5, 4, 3, 2, 1].map((rating) => (
            <Fragment key={rating}>
              <input
                value={rating}
                name="rate"
                id={`star${rating}`}
                type="radio"
                onChange={handleRatingChange}
                checked={selectedRating === String(rating)}
                aria-label={`${rating} Stars`}
              />
              <label htmlFor={`star${rating}`} />
            </Fragment>
          ))}
        </div>
      </div>
      {sideBanners.map((banner, index) => (
        <img
          key={index}
          loading="lazy"
          src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_Side/${banner.image}`}
          alt={`sidebanner${index + 1}`}
          className="col-12 mb-2 rounded-3"
        />
      ))}
    </div>
  )
}

export default OffersSideBar
