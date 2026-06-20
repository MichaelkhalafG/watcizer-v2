import { useState, useContext, useEffect, useRef } from 'react'
import {
  Button,
  Accordion,
  AccordionSummary,
  AccordionDetails,
  Typography,
  Box,
} from '@mui/material'
import { IoIosMenu } from 'react-icons/io'
import { FaAngleDown } from 'react-icons/fa'
import { ExpandMore } from '@mui/icons-material'
import { Link } from 'react-router-dom'
import { MyContext } from '../../../Context/Context'
import { useUIStore } from '../../../Store/uiStore'

const CategoryNavPhone = () => {
  const { products, tables } = useContext(MyContext)
  const { language, setCurrentPage, setFilters } = useUIStore()
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [expandedAccordion, setExpandedAccordion] = useState(null) // Track expanded accordion
  const menuRef = useRef(null)

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (menuRef.current && !menuRef.current.contains(event.target)) {
        setIsMenuOpen(false)
        setExpandedAccordion(null) // Close all accordions when menu closes
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Toggle accordion expansion
  const handleAccordionChange = (id) => {
    setExpandedAccordion(expandedAccordion === id ? null : id) // Open new, close old
  }

  return (
    <Box ref={menuRef} sx={{ position: 'relative', width: '100%' }}>
      {/* Main Category Button */}
      <Button
        fullWidth
        variant="contained"
        sx={{
          background: '#262626E0',
          color: 'white',
          py: 1,
          borderRadius: '8px',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          fontSize: '12px',
          minHeight: '36px',
          '&:hover': { background: '#333' },
        }}
        onClick={() => setIsMenuOpen(!isMenuOpen)}
      >
        <IoIosMenu style={{ fontSize: '18px' }} />
        <Typography sx={{ flexGrow: 1, textAlign: 'center', fontWeight: '500', fontSize: '12px' }}>
          {language === 'ar' ? 'جميع الفئات' : 'All Categories'}
        </Typography>
        <FaAngleDown style={{ fontSize: '14px' }} />
      </Button>

      {/* Side Menu Dropdown */}
      {isMenuOpen && (
        <Box
          sx={{
            position: 'absolute',
            top: '100%',
            left: 0,
            width: '100%',
            background: 'white',
            borderRadius: '0 0 8px 8px',
            boxShadow: '0px 4px 10px rgba(0,0,0,0.1)',
            overflow: 'hidden',
            zIndex: 1050,
            mt: 1,
            maxHeight: '60vh',
            overflowY: 'auto',
          }}
        >
          {tables.subTypes &&
            tables.subTypes
              .filter((subtype) => products.some((product) => product.sub_type_id === subtype.id))
              .map((subtype) => (
                <Accordion
                  key={subtype.id}
                  expanded={expandedAccordion === subtype.id} // Only this accordion expands
                  onChange={() => handleAccordionChange(subtype.id)}
                  sx={{ bgcolor: 'transparent', boxShadow: 'none', minHeight: '30px' }}
                >
                  <AccordionSummary
                    expandIcon={<ExpandMore sx={{ fontSize: '16px' }} />}
                    sx={{
                      px: 2,
                      py: 1,
                      fontSize: '12px',
                      fontWeight: '500',
                      minHeight: '30px',
                      '&:hover': { bgcolor: '#f7f7f7' },
                    }}
                  >
                    {subtype.translations.find((tr) => tr.locale === language)?.sub_type_name}
                  </AccordionSummary>

                  <AccordionDetails sx={{ px: 2, py: 0.5 }}>
                    {tables.brands &&
                      tables.brands
                        .filter((brand) =>
                          products.some(
                            (product) =>
                              product.brand_id === brand.id && product.sub_type_id === subtype.id,
                          ),
                        )
                        .map((brand) => (
                          <Link
                            key={brand.id}
                            to={`/${subtype.translations.find((sup) => sup.locale === 'en')?.sub_type_name}/${brand.translations.find((tr) => tr.locale === 'en')?.brand_name}`}
                            className="text-decoration-none"
                            onClick={() => {
                              setFilters({
                                categories: [],
                                brands: [brand.id],
                                subTypes: [subtype.id],
                                price: [0, 6000],
                              })
                              setCurrentPage(1)
                              setIsMenuOpen(false)
                            }}
                          >
                            <Button
                              fullWidth
                              sx={{
                                textAlign: 'left',
                                justifyContent: 'flex-start',
                                color: '#333',
                                fontSize: '11px',
                                py: 0.5,
                                minHeight: '30px',
                                '&:hover': { bgcolor: '#f5f5f5' },
                              }}
                            >
                              {brand.translations.find((tr) => tr.locale === language)?.brand_name}
                            </Button>
                          </Link>
                        ))}
                  </AccordionDetails>
                </Accordion>
              ))}
        </Box>
      )}
    </Box>
  )
}

export default CategoryNavPhone
