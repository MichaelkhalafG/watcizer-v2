'use client'
import { useEffect, useState } from 'react'
import { useUIStore } from '@/src/Store/uiStore'
import './OfflineBanner.css'

// Spec §10.8 (B8, 2026-10-19): offline is a 44 px banner at the top, never a modal. It appears when the browser
// reports the connection gone and leaves by itself when it is back; nothing on the page is blocked by it.
export default function OfflineBanner() {
  const [offline, setOffline] = useState(false)
  const language = useUIStore((s) => s.language)
  useEffect(() => {
    const sync = () => setOffline(!navigator.onLine)
    // the connection is only known in the browser
    sync()
    window.addEventListener('online', sync)
    window.addEventListener('offline', sync)
    return () => {
      window.removeEventListener('online', sync)
      window.removeEventListener('offline', sync)
    }
  }, [])
  if (!offline) return null
  const ar = language === 'ar'
  return (
    <div className="bf-offline" role="status" dir={ar ? 'rtl' : 'ltr'}>
      <span className="bf-offline__dot" aria-hidden="true" />
      {ar ? 'لا يوجد اتصال بالإنترنت. ستعمل الصفحة من جديد عند عودة الاتصال.' : "You're offline. The page will work again when your connection is back."}
    </div>
  )
}
