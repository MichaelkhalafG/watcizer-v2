import { useCallback, useState } from 'react'
import { useUIStore } from '../../Store/uiStore'
import http from '../../Context/api'
import { GoogleIcon, MicrosoftIcon } from './SocialIcons'

// "Continue with Google / Microsoft". Asks the backend for the OAuth URL, then
// hands the browser off to the provider. The backend redirects back to
// /auth/callback?token=… which AuthCallback consumes.
export default function SocialButtons({ onError }) {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const [busy, setBusy] = useState('')

  const go = useCallback(
    async (provider) => {
      if (busy) return
      setBusy(provider)
      try {
        const { data } = await http.get(`/auth/${provider}/redirect`)
        if (data?.url) {
          window.location.href = data.url
          return
        }
        throw new Error('no url')
      } catch {
        setBusy('')
        onError?.(
          isRTL
            ? 'تعذّر بدء تسجيل الدخول. حاول مرة أخرى.'
            : 'Could not start social sign-in. Please try again.',
        )
      }
    },
    [busy, isRTL, onError],
  )

  return (
    <div className="wz-auth-social">
      <button
        type="button"
        className="wz-auth-social-btn"
        onClick={() => go('google')}
        disabled={!!busy}
      >
        <GoogleIcon />
        <span>{isRTL ? 'المتابعة مع جوجل' : 'Continue with Google'}</span>
      </button>
      {/* Microsoft login hidden on the frontend for now — backend (go('microsoft')
          + MicrosoftIcon) is kept intact and ready to re-enable later.
      <button
        type="button"
        className="wz-auth-social-btn"
        onClick={() => go('microsoft')}
        disabled={!!busy}
      >
        <MicrosoftIcon />
        <span>{isRTL ? 'المتابعة مع مايكروسوفت' : 'Continue with Microsoft'}</span>
      </button>
      */}
    </div>
  )
}
