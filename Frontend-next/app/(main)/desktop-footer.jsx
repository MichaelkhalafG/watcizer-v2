'use client'
import useMediaQuery from '@mui/material/useMediaQuery'
import Footer from '@/src/Components/Footer/Footer'

// Desktop-only footer — mirrors Frontend App.jsx (`isDesktop ? <Footer/> : null`).
export default function DesktopFooter() {
  const isDesktop = useMediaQuery('(min-width:768px)')
  return isDesktop ? <Footer /> : null
}
