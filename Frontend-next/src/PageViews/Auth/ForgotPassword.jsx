'use client'
import { memo, useCallback, useState } from 'react'
import Link from 'next/link'
import http from '../../Context/api'
import { useUIStore } from '../../Store/uiStore'
import AuthShell from '../../Components/Auth/AuthShell'
import './auth.css'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function ForgotPassword() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)

  const [email, setEmail] = useState('')
  const [err, setErr] = useState('')
  const [sent, setSent] = useState(false)
  const [loading, setLoading] = useState(false)

  const handleSubmit = useCallback(
    async (e) => {
      e.preventDefault()
      setErr('')
      if (!EMAIL_RE.test(email.trim())) {
        setErr(t('Please enter a valid email', 'بريد إلكتروني غير صالح'))
        return
      }
      setLoading(true)
      try {
        await http.post('/auth/forgot-password', { email: email.trim() })
      } catch {
        // Intentionally ignored — always show the same neutral message.
      } finally {
        setLoading(false)
        setSent(true)
      }
    },
    [email, isRTL],
  )

  return (
    <AuthShell
      title={t('Reset password', 'إعادة تعيين كلمة المرور')}
      subtitle={t('We will email you a reset link', 'سنرسل لك رابط إعادة التعيين')}
    >
      {sent ? (
        <div className="wz-auth-success">
          <p>
            {t(
              "If an account exists with this email, you'll receive a reset link shortly.",
              'إذا كان هناك حساب بهذا البريد، فستصلك رسالة بإعادة التعيين قريباً.',
            )}
          </p>
          <Link href="/login" className="wz-auth-link-gold">
            {t('Back to sign in', 'العودة لتسجيل الدخول')}
          </Link>
        </div>
      ) : (
        <form className="wz-auth-form" onSubmit={handleSubmit} noValidate>
          <div className="wz-auth-field">
            <label className="wz-auth-label" htmlFor="fp-email">
              {t('Email', 'البريد الإلكتروني')}
            </label>
            <input
              id="fp-email"
              type="email"
              className={`wz-auth-input${err ? ' has-error' : ''}`}
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              disabled={loading}
              autoComplete="email"
              autoFocus
            />
            {err && <span className="wz-auth-err">{err}</span>}
          </div>

          <button type="submit" className="wz-auth-submit" disabled={!email.trim() || loading}>
            {loading ? (
              <>
                <span className="wz-auth-spinner" />
                {t('SENDING...', 'جارٍ الإرسال...')}
              </>
            ) : (
              t('SEND RESET LINK', 'إرسال الرابط')
            )}
          </button>

          <p className="wz-auth-foot">
            <Link href="/login" className="wz-auth-link-muted">
              {t('Back to sign in', 'العودة لتسجيل الدخول')}
            </Link>
          </p>
        </form>
      )}
    </AuthShell>
  )
}

export default memo(ForgotPassword)
