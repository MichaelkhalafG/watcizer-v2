'use client'
import { useEffect, useRef, useState } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import http from '../../Context/api'
import { useAuthStore } from '../../Store/authStore'
import { useUIStore } from '../../Store/uiStore'
import './auth.css'

// Lands here after a social provider redirect: /auth/callback?token=…  (or ?error=…)
// Persists the JWT, fetches the user via /auth/me, then sends them home.
export default function AuthCallback() {
  const params = useSearchParams()
  const router = useRouter()
  const login = useAuthStore((s) => s.login)
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const [failed, setFailed] = useState(false)
  const ran = useRef(false)

  useEffect(() => {
    if (ran.current) return
    ran.current = true

    const token = params.get('token')
    const error = params.get('error')
    if (error || !token) {
      // One-time OAuth-callback failure handling — intentional.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setFailed(true)
      setTimeout(() => router.replace('/login'), 1500)
      return
    }

    // Persist the token so the interceptor authenticates the /auth/me call.
    sessionStorage.setItem('token', token)
    http
      .get('/auth/me')
      .then(({ data }) => {
        login({ ...data, token })
        router.replace('/')
      })
      .catch(() => {
        sessionStorage.removeItem('token')
        setFailed(true)
        setTimeout(() => router.replace('/login'), 1500)
      })
  }, [params, router, login])

  return (
    <div className="wz-auth-callback" dir={isRTL ? 'rtl' : 'ltr'}>
      {failed ? (
        <p>{isRTL ? 'تعذّر تسجيل الدخول. جارٍ إعادة التوجيه...' : 'Sign-in failed. Redirecting…'}</p>
      ) : (
        <>
          <span className="wz-auth-spinner wz-auth-spinner--dark" />
          <p>{isRTL ? 'جارٍ تسجيل الدخول...' : 'Signing you in…'}</p>
        </>
      )}
    </div>
  )
}
