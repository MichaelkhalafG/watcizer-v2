'use client'
import Alert from '@mui/material/Alert'
import Snackbar from '@mui/material/Snackbar'
import useMediaQuery from '@mui/material/useMediaQuery'
import { useToastStore } from '@/src/Store/toastStore'

// Global toast host — the MUI Snackbar/Alert from Frontend App.jsx, verbatim.
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
        horizontal: isDesktop ? 'right' : 'left',
      }}
    >
      <Alert severity={type} onClose={() => hideToast()}>
        {message}
      </Alert>
    </Snackbar>
  )
}
