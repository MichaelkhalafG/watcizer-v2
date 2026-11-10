'use client'
import { useEffect } from 'react'
import { useUIStore } from '../../Store/uiStore'
import { useToastStore } from '../../Store/toastStore'
import { setCouponNotice, setStoredCoupon } from '../../lib/coupon'

// A FRIEND'S INVITE LINK (2026-11-09 evening, WATCHIZER ONLY): the winner's share message carries the shop's address with
// `?invite=FRIEND-XXXX-XXXX`. On landing the code is kept as the typed coupon (src/lib/coupon.js), the visitor is told
// so in a toast, and the parameter is taken off the address (a reload or a share of the page does not re-apply it). A
// `?coupon=` parameter does the same for any code. Mounted once, in the header (every shop page). Nothing on the server.

const CODE = /^[A-Z]{3,10}-[A-Z0-9-]{4,30}$/

export default function InviteFromLink() {
  const language = useUIStore((s) => s.language)
  const showToast = useToastStore((s) => s.showToast)
  useEffect(() => {
    let params
    try {
      params = new URLSearchParams(window.location.search)
    } catch {
      return
    }
    const raw = params.get('invite') || params.get('coupon')
    if (!raw) return
    const code = raw.trim().toUpperCase()
    params.delete('invite')
    params.delete('coupon')
    const rest = params.toString()
    window.history.replaceState(window.history.state, '', window.location.pathname + (rest ? `?${rest}` : '') + window.location.hash)
    if (!CODE.test(code)) return
    setCouponNotice(null)
    setStoredCoupon(code)
    const friend = code.startsWith('FRIEND-')
    showToast(
      language === 'ar'
        ? friend
          ? `دعوة صديقك جاهزة (${code}) — تُطبَّق عند إتمام الطلب، على أول طلب لك.`
          : `الكود ${code} جاهز — يُطبَّق عند إتمام الطلب.`
        : friend
          ? `Your friend's invite is ready (${code}) — it applies at checkout, on your first order.`
          : `Code ${code} is ready — it applies at checkout.`,
      'success',
    )
  }, [language, showToast])
  return null
}
