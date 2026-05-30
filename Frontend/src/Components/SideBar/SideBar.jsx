import {
  useContext,
  useState,
  useEffect,
  // Fragment
} from 'react'
import { MyContext } from '../../Context/Context'
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

function SideBar({ setFilters }) {
  const { language, tables, sideBanners, setCurrentPage } = useContext(MyContext)

  const isRTL = language === 'ar'
  const direction = isRTL ? 'rtl' : 'ltr'

  const [categories, setCategories] = useState([])
  const [brands, setBrands] = useState([])
  const [subTypes, setSubTypes] = useState([])
  const [value, setValue] = useState([0, 6000])
  const [priceRange, setPriceRange] = useState([0, 6000])
  // const [selectedRating, setSelectedRating] = useState(null);
  const [selectedCategories, setSelectedCategories] = useState([])
  const [selectedBrands, setSelectedBrands] = useState([])
  const [selectedSubTypes, setSelectedSubTypes] = useState([])

  useEffect(() => {
    setValue([0, 6000])
  }, [])

  const handlePriceChange = (event, newValue) => {
    setValue(newValue)
    setFilters((prevFilters) => ({ ...prevFilters, price: newValue }))
  }
  useEffect(() => {
    setPriceRange([0, 6000])
  }, [])

  // const handleRatingChange = (event) => {
  //     const rating = event.target.value;
  //     setSelectedRating(rating);
  //     setFilters((prevFilters) => ({ ...prevFilters, rating }));
  // };

  const handleCheckboxChange = (id, filterType) => {
    setCurrentPage(1)
    const updateSelectedItems = (prevSelected) =>
      prevSelected.includes(id)
        ? prevSelected.filter((itemId) => itemId !== id)
        : [...prevSelected, id]

    switch (filterType) {
      case 'categories':
        setSelectedCategories((prev) => {
          const updated = updateSelectedItems(prev)
          setFilters((filters) => ({ ...filters, categories: updated }))
          return updated
        })
        break
      case 'brands':
        setSelectedBrands((prev) => {
          const updated = updateSelectedItems(prev)
          setFilters((filters) => ({ ...filters, brands: updated }))
          return updated
        })
        break
      case 'subTypes':
        setSelectedSubTypes((prev) => {
          const updated = updateSelectedItems(prev)
          setFilters((filters) => ({ ...filters, subTypes: updated }))
          return updated
        })
        break
      default:
        break
    }
  }

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
      const localizedBrands = extractLocalizedNames(tables.brands || [], 'brand_name')
      const localizedSubTypes = extractLocalizedNames(tables.subTypes || [], 'sub_type_name')
      setCategories(localizedCategories)
      setBrands(localizedBrands)
      setSubTypes(localizedSubTypes)
    }
  }, [tables, language])

  useEffect(() => {
    if (setFilters) {
      setSelectedCategories(setFilters.categories || [])
      setSelectedBrands(setFilters.brands || [])
      setSelectedSubTypes(setFilters.subTypes || [])
    }
  }, [setFilters])

  const renderList = (data, key, filterType) => (
    <AccordionDetails className="p-0 m-0 border-0">
      <ul className={`list-unstyled p-0 m-0 row ${isRTL ? 'text-end' : 'text-start'}`}>
        {data.map((item) => (
          <li key={item.id} className="col-6" style={{ fontSize: 'small' }}>
            <FormControlLabel
              control={
                <Checkbox
                  checked={
                    filterType === 'categories'
                      ? selectedCategories.includes(item.id)
                      : filterType === 'brands'
                        ? selectedBrands.includes(item.id)
                        : selectedSubTypes.includes(item.id)
                  }
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
      <div className="tables-list-filter" style={{ height: '30vh', overflowY: 'auto' }}>
        <p className={`fs-6 fw-bold color-most-used p-2 pb-0 ${isRTL ? 'text-end' : 'text-start'}`}>
          {isRTL ? 'التصنيف بالنوع.' : 'FILTER BY TYPE.'}
        </p>
        {[
          {
            name: isRTL ? 'فئات' : 'Categories',
            data: categories,
            key: 'category_type_name',
            filterType: 'categories',
          },
          {
            name: isRTL ? 'البراند' : 'Brands',
            data: brands,
            key: 'brand_name',
            filterType: 'brands',
          },
          {
            name: isRTL ? 'النوع الفرعي' : 'Sub Types',
            data: subTypes,
            key: 'sub_type_name',
            filterType: 'subTypes',
          },
        ].map(({ name, data, key, filterType }) => (
          <Accordion key={name} className="border-0 shadow-none fs-5 p-0">
            <AccordionSummary className="border-0" expandIcon={<ExpandMoreIcon />}>
              <Typography className="border-0">{name}</Typography>
            </AccordionSummary>
            {renderList(data, key, filterType)}
          </Accordion>
        ))}
      </div>
      <p
        className={`fs-6 fw-bold color-most-used p-4 ps-2 pb-0 ${isRTL ? 'text-end' : 'text-start'}`}
      >
        {isRTL ? 'التصنيف بالسعر.' : 'FILTER BY PRICE.'}
      </p>
      <div className="price-filter col-12 m-0 px-2 row">
        <div className="col-12">
          <Slider
            min={priceRange[0]}
            max={priceRange[1]}
            getAriaLabel={() => 'Price range'}
            value={value}
            onChange={handlePriceChange}
            valueLabelDisplay="auto"
            getAriaValueText={valuetext}
          />
        </div>
        <p className={`col-5 p-1 color-most-used ${isRTL ? 'text-end' : 'text-start'}`}>
          {isRTL ? 'من:' : 'from:'} <span className="fw-bold">{value[0]}</span>
        </p>
        <p className="col-2 p-1" />
        <p className={`col-5 p-1 ${isRTL ? 'text-start' : 'text-end'} color-most-used`}>
          {isRTL ? 'الى:' : 'to:'} <span className="fw-bold">{value[1]}</span>
        </p>
      </div>
      {/* <p className={`fs-6 fw-bold color-most-used p-4 ps-2 pb-0 ${isRTL ? "text-end" : "text-start"}`}>
                {isRTL ? "التصنيف بالتقييم." : "FILTER BY RATING."}
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
                            <label htmlFor={`star${rating}`}></label>
                        </Fragment>
                    ))}
                </div>
            </div> */}
      <div className="col-12 m-0 px-2 row">
        {sideBanners.map((banner, index) => (
          <img
            key={index}
            width="100%"
            height="auto"
            loading="lazy"
            src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_Side/${banner.image}`}
            alt={`sidebanner${index + 1}`}
            className="col-12 mb-2 rounded-4"
          />
        ))}
      </div>
    </div>
  )
}

export default SideBar
