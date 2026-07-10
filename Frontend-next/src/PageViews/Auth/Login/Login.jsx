'use client'
import { memo, useCallback, useEffect, useRef, useState } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { FiEye, FiEyeOff } from 'react-icons/fi'
import DOMPurify from 'dompurify'
import http from '../../../Context/api'
import { cartStore } from '../../../Store/cartStore'
import { useAuthStore } from '../../../Store/authStore'
import { useUIStore } from '../../../Store/uiStore'
import { useToastStore } from '../../../Store/toastStore'
import AuthShell from '../../../Components/Auth/AuthShell'
import SocialButtons from '../../../Components/Auth/SocialButtons'
import '../auth.css'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function Login() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const router = useRouter()
  const login = useAuthStore((s) => s.login)
  const showToast = useToastStore((s) => s.showToast)
  // Next has no navigation state; post-login always returns home (the SPA's
  // location.state.from is never set by our guards).
  const from = '/'

  const emailRef = useRef(null)
  // localStorage isn't available during SSR — start empty and hydrate the
  // remembered email after mount (server + first client render agree → no mismatch).
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [remember, setRemember] = useState(false)
  useEffect(() => {
    const saved = localStorage.getItem('wz_remember_email')
    if (saved) {
      // Hydrate remembered email from localStorage after mount — intentional.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setEmail(saved)
      setRemember(true)
    }
  }, [])
  const [fieldErr, setFieldErr] = useState({})
  const [banner, setBanner] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    emailRef.current?.focus()
  }, [])

  // Auto-dismiss the general error banner.
  useEffect(() => {
    if (!banner) return
    const id = setTimeout(() => setBanner(''), 5000)
    return () => clearTimeout(id)
  }, [banner])

  const validate = useCallback(() => {
    const errs = {}
    if (!EMAIL_RE.test(email.trim())) errs.email = t('Please enter a valid email', 'بريد إلكتروني غير صالح')
    if (!password) errs.password = t('Password is required', 'كلمة المرور مطلوبة')
    setFieldErr(errs)
    return Object.keys(errs).length === 0
  }, [email, password, isRTL])

  const handleSubmit = useCallback(
    async (e) => {
      e.preventDefault()
      setBanner('')
      if (loading || !validate()) return
      setLoading(true)
      try {
        const cleanEmail = DOMPurify.sanitize(email.trim())
        const cleanPassword = DOMPurify.sanitize(password)
        const { data } = await http.post('/login', { email: cleanEmail, password: cleanPassword })
        login(data)
        if (remember) localStorage.setItem('wz_remember_email', cleanEmail)
        else localStorage.removeItem('wz_remember_email')
        // Merge any guest cart into the now-authenticated account (non-blocking).
        cartStore.mergeGuestCart().then((res) => {
          if (res) showToast(t('Your cart has been saved', 'تم حفظ سلتك'), 'success')
        })
        router.replace(from)
      } catch (err) {
        const status = err.response?.status
        if (status === 422 && err.response.data?.errors) {
          const apiErrs = err.response.data.errors
          setFieldErr({
            email: apiErrs.email?.[0],
            password: apiErrs.password?.[0],
          })
        } else if (status === 401) {
          setBanner(t('Invalid email or password.', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'))
        } else if (status === 429) {
          setBanner(
            err.response.data?.error ||
              t('Too many attempts. Please wait a moment.', 'محاولات كثيرة. انتظر قليلاً.'),
          )
        } else {
          setBanner(t('Something went wrong. Please try again.', 'حدث خطأ ما. حاول مرة أخرى.'))
        }
      } finally {
        setLoading(false)
      }
    },
    [email, password, remember, loading, from, login, router, validate, isRTL],
  )

  const canSubmit = email.trim() && password && !loading

  return (
    <AuthShell title={t('Welcome back', 'مرحباً بعودتك')} subtitle={t('Sign in to your account', 'سجّل دخولك')}>
      {banner && <div className="wz-auth-banner">{banner}</div>}

      <form className="wz-auth-form" onSubmit={handleSubmit} noValidate>
        <div className="wz-auth-field">
          <label className="wz-auth-label" htmlFor="login-email">
            {t('Email', 'البريد الإلكتروني')}
          </label>
          <input
            id="login-email"
            ref={emailRef}
            type="email"
            className={`wz-auth-input${fieldErr.email ? ' has-error' : ''}`}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            disabled={loading}
            autoComplete="email"
          />
          {fieldErr.email && <span className="wz-auth-err">{fieldErr.email}</span>}
        </div>

        <div className="wz-auth-field">
          <label className="wz-auth-label" htmlFor="login-password">
            {t('Password', 'كلمة المرور')}
          </label>
          <div className="wz-auth-input-wrap">
            <input
              id="login-password"
              type={showPw ? 'text' : 'password'}
              className={`wz-auth-input${fieldErr.password ? ' has-error' : ''}`}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              disabled={loading}
              autoComplete="current-password"
            />
            <button
              type="button"
              className="wz-auth-eye"
              onClick={() => setShowPw((s) => !s)}
              aria-label={showPw ? t('Hide password', 'إخفاء') : t('Show password', 'إظهار')}
              tabIndex={-1}
            >
              {showPw ? <FiEyeOff /> : <FiEye />}
            </button>
          </div>
          {fieldErr.password && <span className="wz-auth-err">{fieldErr.password}</span>}
        </div>

        <div className="wz-auth-row">
          <label className="wz-auth-check">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
            <span>{t('Remember me', 'تذكرني')}</span>
          </label>
          <Link href="/forgot-password" className="wz-auth-link-muted">
            {t('Forgot password?', 'نسيت كلمة المرور؟')}
          </Link>
        </div>

        <button type="submit" className="wz-auth-submit" disabled={!canSubmit}>
          {loading ? (
            <>
              <span className="wz-auth-spinner" />
              {t('SIGNING IN...', 'جارٍ تسجيل الدخول...')}
            </>
          ) : (
            t('SIGN IN', 'تسجيل الدخول')
          )}
        </button>
      </form>

      <div className="wz-auth-divider">
        <span>{t('or', 'أو')}</span>
      </div>

      <SocialButtons onError={setBanner} />

      <p className="wz-auth-foot">
        {t("Don't have an account?", 'ليس لديك حساب؟')}{' '}
        <Link href="/register" className="wz-auth-link-gold">
          {t('Create one', 'إنشاء حساب')}
        </Link>
      </p>
    </AuthShell>
  )
}

export default memo(Login)
