import Link from 'next/link'

// PLACEHOLDER — second page for the next/link navigation test.
export default function PreviewPlaceholder() {
  return (
    <div style={{ padding: 48, textAlign: 'center' }}>
      <h1 style={{ marginBottom: 12 }}>Preview (placeholder)</h1>
      <p style={{ marginBottom: 20, color: 'rgba(0,0,0,0.55)' }}>
        Navigated here via next/link — the chrome (header/footer) persisted.
      </p>
      <Link href="/" style={{ color: '#9a7b3c', textDecoration: 'underline' }}>
        ← Back to home
      </Link>
    </div>
  )
}
