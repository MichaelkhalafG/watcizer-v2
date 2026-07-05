'use client'
import { useUIStore } from '@/src/Store/uiStore'

// Bilingual skip link (first focusable) — mirrors Frontend App.jsx.
export default function SkipLink() {
  const language = useUIStore((s) => s.language)
  return (
    <a href="#main-content" className="wz-skip-link">
      {language === 'ar' ? 'تخطَّ إلى المحتوى الرئيسي' : 'Skip to main content'}
    </a>
  )
}
