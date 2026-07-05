'use client'
import { useEffect } from 'react'
import { MdLanguage } from 'react-icons/md'
import { useUIStore } from '../../Store/uiStore'
import './LanguageDropdowen.css'

// Two languages only → a single button that shows the OTHER language and
// switches to it on one click. No dropdown, no dialog.
export default function LanguageDropdown() {
  const { language, setLanguage } = useUIStore()

  // Keep <html dir/lang> in sync whenever the language changes (covers initial
  // mount + restored persisted language, not just the click handler).
  useEffect(() => {
    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr'
    document.documentElement.lang = language
  }, [language])

  const toggle = () => {
    const next = language === 'ar' ? 'en' : 'ar'
    setLanguage(next)
    document.documentElement.dir = next === 'ar' ? 'rtl' : 'ltr'
    document.documentElement.lang = next
  }

  return (
    <button
      type="button"
      className="wz-lang-toggle"
      onClick={toggle}
      aria-label="Switch language"
      title="Switch language / تغيير اللغة"
    >
      <MdLanguage className="wz-lang-toggle__icon" />
      <span>{language === 'ar' ? 'EN' : 'عربي'}</span>
    </button>
  )
}