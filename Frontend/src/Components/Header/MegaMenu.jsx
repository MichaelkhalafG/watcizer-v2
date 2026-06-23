import { useState, useContext } from 'react'
import { Link } from 'react-router-dom'
import { useMediaQuery } from '@mui/material'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'

// Adapted to the real legacy `tables` shape (camelCase `subTypes`/`brands`,
// labels live in a `translations` array) and to the routes that actually exist
// (`/subtypes/:name`, `/brand/:name`). Light luxury theme, existing colors only.
const MegaMenu = ({ tables }) => {
  const [open, setOpen] = useState(false)
  const { products } = useContext(MyContext)
  const { language, setCurrentPage, setFilters } = useUIStore()
  const isMobile = useMediaQuery('(max-width:768px)')

  const tr = (item, key) =>
    item?.translations?.find((t) => t.locale === language)?.[key] ??
    item?.translations?.find((t) => t.locale === 'en')?.[key] ??
    ''
  const enName = (item, key) => item?.translations?.find((t) => t.locale === 'en')?.[key] ?? ''

  const subTypes = (tables?.subTypes || [])
    .filter((s) => (products || []).some((p) => p.sub_type_id === s.id))
    .slice(0, 8)
  const brands = (tables?.brands || [])
    .filter((b) => (products || []).some((p) => p.brand_id === b.id))
    .slice(0, 8)

  const selectSubType = (s) => {
    setFilters({ categories: [], brands: [], subTypes: [s.id], price: [0, 6000] })
    setCurrentPage(1)
    setOpen(false)
  }
  const selectBrand = (b) => {
    setFilters({ categories: [], brands: [b.id], subTypes: [], price: [0, 6000] })
    setCurrentPage(1)
    setOpen(false)
  }

  const linkStyle = {
    display: 'block',
    padding: '7px 0',
    color: '#262626',
    opacity: 0.65,
    textDecoration: 'none',
    fontSize: '12px',
    letterSpacing: '0.04em',
    borderBottom: '1px solid rgba(0,0,0,0.05)',
    transition: 'opacity 0.2s ease, padding-left 0.2s ease',
  }
  const onLinkEnter = (e) => {
    e.currentTarget.style.opacity = '1'
    e.currentTarget.style.paddingLeft = '8px'
  }
  const onLinkLeave = (e) => {
    e.currentTarget.style.opacity = '0.65'
    e.currentTarget.style.paddingLeft = '0px'
  }
  const headerStyle = {
    color: '#262626',
    fontSize: '9px',
    letterSpacing: '0.3em',
    textTransform: 'uppercase',
    marginBottom: 16,
    opacity: 0.4,
  }

  if (isMobile) {
    // Accordion on mobile
    return (
      <div>
        <button
          onClick={() => setOpen(!open)}
          style={{
            background: 'none',
            border: 'none',
            color: '#262626',
            cursor: 'pointer',
            fontSize: '0.85rem',
            letterSpacing: '0.1em',
          }}
        >
          {language === 'ar' ? 'الفئات' : 'Categories'} {open ? '▲' : '▼'}
        </button>
        {open && (
          <div style={{ padding: '16px 0' }}>
            {subTypes.map((s) => (
              <Link
                key={s.id}
                to={`/subtypes/${encodeURIComponent(enName(s, 'sub_type_name'))}`}
                onClick={() => selectSubType(s)}
                style={{
                  display: 'block',
                  padding: '8px 16px',
                  color: '#262626',
                  opacity: 0.7,
                  textDecoration: 'none',
                  fontSize: '0.85rem',
                }}
              >
                {tr(s, 'sub_type_name')}
              </Link>
            ))}
          </div>
        )}
      </div>
    )
  }

  // Desktop mega menu
  return (
    <div
      style={{ position: 'relative' }}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        className="nav-link-item"
        style={{ opacity: open ? 1 : undefined }}
      >
        {language === 'ar' ? 'الفئات' : 'Categories'}
        <span
          style={{
            display: 'inline-block',
            fontSize: '10px',
            transition: 'transform 0.2s ease',
            transform: open ? 'rotate(180deg)' : 'rotate(0deg)',
          }}
        >
          ▾
        </span>
      </button>

      {open && (
        <div
          style={{
            position: 'absolute',
            top: '100%',
            left: 0,
            width: '480px',
            background: '#fff',
            border: '1px solid rgba(0,0,0,0.08)',
            borderTop: '2px solid #ea2b0f',
            boxShadow: '0 16px 48px rgba(0,0,0,0.12)',
            padding: '24px',
            display: 'grid',
            gridTemplateColumns: '1fr 1fr',
            gap: '24px',
            zIndex: 1000,
            animation: 'megaFadeIn 0.2s ease',
          }}
        >
          {/* Left: Sub types */}
          <div>
            <p style={headerStyle}>{language === 'ar' ? 'الأنواع' : 'Categories'}</p>
            {subTypes.map((s) => (
              <Link
                key={s.id}
                to={`/subtypes/${encodeURIComponent(enName(s, 'sub_type_name'))}`}
                onClick={() => selectSubType(s)}
                style={linkStyle}
                onMouseEnter={onLinkEnter}
                onMouseLeave={onLinkLeave}
              >
                {tr(s, 'sub_type_name')}
              </Link>
            ))}
          </div>

          {/* Right: Brands */}
          <div>
            <p style={headerStyle}>{language === 'ar' ? 'البراندات' : 'Brands'}</p>
            {brands.map((b) => (
              <Link
                key={b.id}
                to={`/brand/${encodeURIComponent(enName(b, 'brand_name'))}`}
                onClick={() => selectBrand(b)}
                style={linkStyle}
                onMouseEnter={onLinkEnter}
                onMouseLeave={onLinkLeave}
              >
                {tr(b, 'brand_name')}
              </Link>
            ))}
          </div>
        </div>
      )}
      <style>{`@keyframes megaFadeIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}`}</style>
    </div>
  )
}
export default MegaMenu
