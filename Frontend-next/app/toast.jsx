'use client'
import Alert from '@mui/material/Alert'
import Snackbar from '@mui/material/Snackbar'
import useMediaQuery from '@mui/material/useMediaQuery'
import { useToastStore } from '@/src/Store/toastStore'

// Global toast host — the MUI Snackbar/Alert from Frontend App.jsx.
//
// ABOVE the cart drawer (2026-09-29). MUI's default z-index (1400) put every toast under the cart
// drawer (2500) and its overlay (2499) — and the drawer opens on an add, so the product page's
// "Added to cart!" and any refusal raised while it was open were in the page but covered
// (measured with elementFromPoint). 3100 clears the drawer and the nav drawer (3000/3001).
// Desktop sits bottom-CENTRE, not bottom-right, so it does not land on the drawer's footer buttons.
export default function Toast() {
  const { open, type, message, hideToast } = useToastStore()
  const isDesktop = useMediaQuery('(min-width:768px)')
  return (
    <Snackbar
      open={open}
      autoHideDuration={3000}
      onClose={() => hideToast()}
      anchorOrigin={{
        vertical: isDesktop ? 'bottom' : 'top',
        horizontal: isDesktop ? 'center' : 'left',
      }}
      sx={{ zIndex: 3100 }}
    >
      <Alert severity={type} onClose={() => hideToast()}>
        {message}
      </Alert>
    </Snackbar>
  )
}
