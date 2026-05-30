import { useState } from 'react'
import { Modal, Box, Button, TextField, Alert, IconButton, InputAdornment } from '@mui/material'
import { Visibility, VisibilityOff } from '@mui/icons-material'
import DOMPurify from 'dompurify'
import http from '../../../Context/api'

const modalStyle = {
  position: 'absolute',
  top: '50%',
  left: '50%',
  transform: 'translate(-50%, -50%)',
  width: 400,
  bgcolor: 'background.paper',
  borderRadius: 3,
  boxShadow: 24,
  p: 4,
}

function LoginModal({ open, onClose, onLoginSuccess }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [loading, setLoading] = useState(false)

  const handleLogin = async (e) => {
    e.preventDefault()
    setError('')
    setSuccess('')
    setLoading(true)

    const cleanEmail = DOMPurify.sanitize(email.trim())
    const cleanPassword = DOMPurify.sanitize(password.trim())

    if (!cleanEmail || !cleanPassword) {
      setError('Email and password are required.')
      setLoading(false)
      return
    }

    try {
      const response = await http.post(
        `/login?email=${encodeURIComponent(cleanEmail)}&password=${encodeURIComponent(cleanPassword)}`,
      )

      const data = response.data
      setSuccess(`Welcome, ${data.first_name} ${data.last_name}!`)
      sessionStorage.setItem('user_id', data.id)
      sessionStorage.setItem('first_name', data.first_name)
      sessionStorage.setItem('last_name', data.last_name)
      sessionStorage.setItem('email', data.email)
      sessionStorage.setItem('phone_number', data.phone_number)
      sessionStorage.setItem(
        'image',
        data.image ? `${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/User/${data.image}` : null,
      )
      sessionStorage.setItem('token', data.token)
      window.location.reload()
      if (onLoginSuccess) onLoginSuccess(data)
      setTimeout(() => {
        setSuccess('')
        onClose()
      }, 1000)
    } catch (err) {
      if (err.response) {
        setError(err.response.data?.message || 'Login failed. your email or password is incorrect.')
      } else {
        setError('An error occurred. Please check your connection and try again.')
      }
    }
    setLoading(false)
  }

  return (
    <Modal open={open} onClose={onClose}>
      <Box sx={modalStyle}>
        <h2 className="text-center">Login</h2>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}
        {success && (
          <Alert severity="success" sx={{ mb: 2 }}>
            {success}
          </Alert>
        )}
        <form onSubmit={handleLogin}>
          <TextField
            label="Email address"
            type="email"
            fullWidth
            margin="normal"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <TextField
            label="Password"
            type={showPassword ? 'text' : 'password'}
            fullWidth
            margin="normal"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            InputProps={{
              endAdornment: (
                <InputAdornment position="end">
                  <IconButton onClick={() => setShowPassword((show) => !show)} edge="end">
                    {showPassword ? <VisibilityOff /> : <Visibility />}
                  </IconButton>
                </InputAdornment>
              ),
            }}
          />
          <Button
            type="submit"
            variant="contained"
            color="primary"
            fullWidth
            sx={{ mt: 2 }}
            disabled={loading}
          >
            {loading ? 'Logging in...' : 'Login'}
          </Button>
        </form>
      </Box>
    </Modal>
  )
}

export default LoginModal
