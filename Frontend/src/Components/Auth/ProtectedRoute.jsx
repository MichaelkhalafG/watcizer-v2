import { Navigate, useLocation } from 'react-router-dom'
import { useAuthStore } from '../../Store/authStore'

// Gates a route behind authentication. Unauthenticated users are bounced to
// /login with the attempted location preserved so we can return them after sign-in.
export default function ProtectedRoute({ children }) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const location = useLocation()

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }
  return children
}
