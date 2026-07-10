'use client'
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { FiEye, FiEyeOff, FiCheck, FiX } from 'react-icons/fi'
import DOMPurify from 'dompurify'
import http from '../../../Context/api'
import { useAuthStore } from '../../../Store/authStore'
import { useUIStore } from '../../../Store/uiStore'
import { useToastStore } from '../../../Store/toastStore'
import AuthShell from '../../../Components/Auth/AuthShell'
import SocialButtons from '../../../Components/Auth/SocialButtons'
import '../auth.css'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// A password-criterion pill. Module-scoped (not defined inside Register) so it
// isn't re-created on every render — react-hooks/static-components.
const Criterion = ({ met, label }) => (
  <span className={`wz-auth-crit${met ? ' is-met' : ''}`}>
    {met ? <FiCheck /> : <FiX />} {label}
  </span>
)

function Register() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const router = useRouter()
  const login = useAuthStore((s) => s.login)
  const showToast = useToastStore((s) => s.showToast)

  const nameRef = useRef(null)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [fieldErr, setFieldErr] = useState({})
  const [banner, setBanner] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    nameRef.current?.focus()
  }, [])
  useEffect(() => {
    if (!banner) return
    const id = setTimeout(() => setBanner(''), 5000)
    return () => clearTimeout(id)
  }, [banner])

  // Password strength criteria.
  const criteria = useMemo(
    () => ({
      length: password.length >= 8,
      number: /\d/.test(password),
      upper: /[A-Z]/.test(password),
    }),
    [password],
  )
  const score = Object.values(criteria).filter(Boolean).length
  const strength = score <= 1 ? 'weak' : score === 2 ? 'medium' : 'strong'
  const strengthLabel = { weak: t('Weak', 'ضعيفة'), medium: t('Medium', 'متوسطة'), strong: t('Strong', 'قوية') }[
    strength
  ]

  const confirmMismatch = confirm.length > 0 && confirm !== password

  const validate = useCallback(() => {
    const errs = {}
    const parts = name.trim().split(/\s+/).filter(Boolean)
    if (parts.length < 2) errs.name = t('Please enter your full name', 'يرجى إدخال الاسم الكامل')
    if (!EMAIL_RE.test(email.trim())) errs.email = t('Please enter a valid email', 'بريد إلكتروني غير صالح')
    if (score < 3) errs.password = t('Password is too weak', 'كلمة المرور ضعيفة')
    if (confirm !== password) errs.confirm = t("Passwords don't match", 'كلمتا المرور غير متطابقتين')
    setFieldErr(errs)
    return Object.keys(errs).length === 0
  }, [name, email, password, confirm, score, isRTL])

  const handleSubmit = useCallback(
    async (e) => {
      e.preventDefault()
      setBanner('')
      if (loading || !validate()) return
      setLoading(true)
      try {
        const parts = name.trim().split(/\s+/).filter(Boolean)
        const first = DOMPurify.sanitize(parts.shift())
        const last = DOMPurify.sanitize(parts.join(' '))
        const { data } = await http.post('/register', {
          first_name: first,
          last_name: last,
          email: DOMPurify.sanitize(email.trim()),
          password,
          password_confirmation: confirm,
        })
        login(data)
        showToast(
          t('Account created! Please verify your email.', 'تم إنشاء الحساب! يرجى تأكيد بريدك الإلكتروني.'),
          'success',
        )
        router.replace('/')
      } catch (err) {
        const status = err.response?.status
        if (status === 422 && err.response.data?.errors) {
          const a = err.response.data.errors
          setFieldErr({
            name: a.first_name?.[0] || a.last_name?.[0],
            email: a.email?.[0],
            password: a.password?.[0],
          })
        } else {
          setBanner(t('Something went wrong. Please try again.', 'حدث خطأ ما. حاول مرة أخرى.'))
        }
      } finally {
        setLoading(false)
      }
    },
    [name, email, password, confirm, loading, login, router, showToast, validate, isRTL],
  )

  const canSubmit = name.trim() && email.trim() && password && confirm && !loading

  return (
    <AuthShell
      title={t('Create account', 'إنشاء حساب')}
      subtitle={t('Join the world of luxury', 'انضم إلى عالم الفخامة')}
    >
      {banner && <div className="wz-auth-banner">{banner}</div>}

      <form className="wz-auth-form" onSubmit={handleSubmit} noValidate>
        <div className="wz-auth-field">
          <label className="wz-auth-label" htmlFor="reg-name">
            {t('Full Name', 'الاسم الكامل')}
          </label>
          <input
            id="reg-name"
            ref={nameRef}
            type="text"
            className={`wz-auth-input${fieldErr.name ? ' has-error' : ''}`}
            value={name}
            onChange={(e) => setName(e.target.value)}
            disabled={loading}
            autoComplete="name"
          />
          {fieldErr.name && <span className="wz-auth-err">{fieldErr.name}</span>}
        </div>

        <div className="wz-auth-field">
          <label className="wz-auth-label" htmlFor="reg-email">
            {t('Email', 'البريد الإلكتروني')}
          </label>
          <input
            id="reg-email"
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
          <label className="wz-auth-label" htmlFor="reg-password">
            {t('Password', 'كلمة المرور')}
          </label>
          <div className="wz-auth-input-wrap">
            <input
              id="reg-password"
              type={showPw ? 'text' : 'password'}
              className={`wz-auth-input${fieldErr.password ? ' has-error' : ''}`}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              disabled={loading}
              autoComplete="new-password"
            />
            <button
              type="button"
              className="wz-auth-eye"
              onClick={() => setShowPw((s) => !s)}
              aria-label={showPw ? t('Hide', 'إخفاء') : t('Show', 'إظهار')}
              tabIndex={-1}
            >
              {showPw ? <FiEyeOff /> : <FiEye />}
            </button>
          </div>

          {password && (
            <>
              <div className="wz-auth-strength">
                <div className={`wz-auth-strength-bar is-${strength}`} />
                <span className={`wz-auth-strength-text is-${strength}`}>{strengthLabel}</span>
              </div>
              <div className="wz-auth-crits">
                <Criterion met={criteria.length} label={t('8+ characters', '8 أحرف على الأقل')} />
                <Criterion met={criteria.number} label={t('One number', 'رقم واحد')} />
                <Criterion met={criteria.upper} label={t('One uppercase', 'حرف كبير')} />
              </div>
            </>
          )}
          {fieldErr.password && <span className="wz-auth-err">{fieldErr.password}</span>}
        </div>

        <div className="wz-auth-field">
          <label className="wz-auth-label" htmlFor="reg-confirm">
            {t('Confirm Password', 'تأكيد كلمة المرور')}
          </label>
          <input
            id="reg-confirm"
            type={showPw ? 'text' : 'password'}
            className={`wz-auth-input${confirmMismatch || fieldErr.confirm ? ' has-error' : ''}`}
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            disabled={loading}
            autoComplete="new-password"
          />
          {(confirmMismatch || fieldErr.confirm) && (
            <span className="wz-auth-err">{t("Passwords don't match", 'كلمتا المرور غير متطابقتين')}</span>
          )}
        </div>

        <button type="submit" className="wz-auth-submit" disabled={!canSubmit}>
          {loading ? (
            <>
              <span className="wz-auth-spinner" />
              {t('CREATING...', 'جارٍ الإنشاء...')}
            </>
          ) : (
            t('CREATE ACCOUNT', 'إنشاء حساب')
          )}
        </button>

        <p className="wz-auth-terms">
          {t('By creating an account, you agree to our ', 'بإنشاء حساب، فإنك توافق على ')}
          <a href="#" className="wz-auth-link-gold">
            {t('Terms of Service', 'شروط الخدمة')}
          </a>{' '}
          {t('and', 'و')}{' '}
          <a href="#" className="wz-auth-link-gold">
            {t('Privacy Policy', 'سياسة الخصوصية')}
          </a>
        </p>
      </form>

      <div className="wz-auth-divider">
        <span>{t('or', 'أو')}</span>
      </div>

      <SocialButtons onError={setBanner} />

      <p className="wz-auth-foot">
        {t('Already have an account?', 'لديك حساب بالفعل؟')}{' '}
        <Link href="/login" className="wz-auth-link-gold">
          {t('Sign in', 'تسجيل الدخول')}
        </Link>
      </p>
    </AuthShell>
  )
}

export default memo(Register)
