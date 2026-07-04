import { memo, useCallback, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { FiEye, FiEyeOff } from 'react-icons/fi'
import http from '../../Context/api'
import { useUIStore } from '../../Store/uiStore'
import { useToastStore } from '../../Store/toastStore'
import AuthShell from '../../Components/Auth/AuthShell'
import './auth.css'

function ResetPassword() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const navigate = useNavigate()
  const showToast = useToastStore((s) => s.showToast)
  const [params] = useSearchParams()
  const token = params.get('token') || ''
  const email = params.get('email') || ''

  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(false)

  const strongEnough = useMemo(
    () => password.length >= 8 && /\d/.test(password) && /[A-Z]/.test(password),
    [password],
  )
  const mismatch = confirm.length > 0 && confirm !== password
  const invalidLink = !token || !email

  const handleSubmit = useCallback(
    async (e) => {
      e.preventDefault()
      setErr('')
      if (!strongEnough) {
        setErr(t('Password must be 8+ chars with a number and an uppercase letter', 'كلمة المرور ضعيفة'))
        return
      }
      if (password !== confirm) {
        setErr(t("Passwords don't match", 'كلمتا المرور غير متطابقتين'))
        return
      }
      setLoading(true)
      try {
        await http.post('/auth/reset-password', {
          token,
          email,
          password,
          password_confirmation: confirm,
        })
        showToast(t('Password reset! Please sign in.', 'تم إعادة التعيين! سجّل الدخول.'), 'success')
        navigate('/login', { replace: true })
      } catch (errResp) {
        setErr(
          errResp.response?.data?.error ||
            t('This reset link is invalid or has expired.', 'هذا الرابط غير صالح أو منتهي الصلاحية.'),
        )
      } finally {
        setLoading(false)
      }
    },
    [token, email, password, confirm, strongEnough, navigate, showToast, isRTL],
  )

  return (
    <AuthShell
      title={t('New password', 'كلمة مرور جديدة')}
      subtitle={t('Choose a strong new password', 'اختر كلمة مرور قوية')}
    >
      {invalidLink ? (
        <div className="wz-auth-success">
          <p>{t('This reset link is invalid.', 'هذا الرابط غير صالح.')}</p>
          <Link to="/forgot-password" className="wz-auth-link-gold">
            {t('Request a new link', 'اطلب رابطاً جديداً')}
          </Link>
        </div>
      ) : (
        <form className="wz-auth-form" onSubmit={handleSubmit} noValidate>
          {err && <div className="wz-auth-banner">{err}</div>}

          <div className="wz-auth-field">
            <label className="wz-auth-label" htmlFor="rp-password">
              {t('New Password', 'كلمة المرور الجديدة')}
            </label>
            <div className="wz-auth-input-wrap">
              <input
                id="rp-password"
                type={showPw ? 'text' : 'password'}
                className="wz-auth-input"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={loading}
                autoComplete="new-password"
                autoFocus
              />
              <button
                type="button"
                className="wz-auth-eye"
                onClick={() => setShowPw((s) => !s)}
                tabIndex={-1}
                aria-label={showPw ? t('Hide', 'إخفاء') : t('Show', 'إظهار')}
              >
                {showPw ? <FiEyeOff /> : <FiEye />}
              </button>
            </div>
          </div>

          <div className="wz-auth-field">
            <label className="wz-auth-label" htmlFor="rp-confirm">
              {t('Confirm Password', 'تأكيد كلمة المرور')}
            </label>
            <input
              id="rp-confirm"
              type={showPw ? 'text' : 'password'}
              className={`wz-auth-input${mismatch ? ' has-error' : ''}`}
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              disabled={loading}
              autoComplete="new-password"
            />
            {mismatch && (
              <span className="wz-auth-err">{t("Passwords don't match", 'كلمتا المرور غير متطابقتين')}</span>
            )}
          </div>

          <button
            type="submit"
            className="wz-auth-submit"
            disabled={!password || !confirm || loading}
          >
            {loading ? (
              <>
                <span className="wz-auth-spinner" />
                {t('RESETTING...', 'جارٍ التعيين...')}
              </>
            ) : (
              t('RESET PASSWORD', 'إعادة تعيين')
            )}
          </button>
        </form>
      )}
    </AuthShell>
  )
}

export default memo(ResetPassword)
