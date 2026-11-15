'use client'
import { useEffect } from 'react'
import { MdLanguage } from 'react-icons/md'
import { useUIStore } from '../../Store/uiStore'
import LanguageLink from './LanguageLink'
import './LanguageDropdowen.css'

// Two languages only → a single button that shows the OTHER language and
// switches to it on one click. No dropdown, no dialog.
export default function LanguageDropdown() {
  const language = useUIStore((s) => s.language)

  // Keep <html dir/lang> in sync whenever the language changes (covers initial
  // mount + restored persisted language, not just the click handler).
  useEffect(() => {
    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr'
    document.documentElement.lang = language
  }, [language])

  // a real link to this page in the other language (2026-11-14, LanguageLink)
  const next = language === 'ar' ? 'en' : 'ar'

  return (
    <LanguageLink
      lang={next}
      className="wz-lang-toggle"
      aria-label={language === 'ar' ? 'Switch language to EN' : 'Switch language to عربي'}
      title="Switch language / تغيير اللغة"
    >
      <MdLanguage className="wz-lang-toggle__icon" />
      <span>{language === 'ar' ? 'EN' : 'عربي'}</span>
    </LanguageLink>
  )
}