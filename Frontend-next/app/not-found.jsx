import Link from 'next/link'

// Global 404 (App Router). notFound() from any route — e.g. an unknown product/
// offer slug — renders this with a real 404 status so scrapers/search engines
// treat missing items correctly (no soft-200).
export const metadata = {
  title: 'Page not found | Watchizer',
  robots: 'noindex, follow',
}

export default function NotFound() {
  return (
    <div
      style={{
        minHeight: '60vh',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        textAlign: 'center',
        gap: '1rem',
        padding: '4rem 1.5rem',
      }}
    >
      <p style={{ fontSize: '3.5rem', fontWeight: 700, margin: 0, letterSpacing: '0.02em', color: '#C8A45C', fontFamily: 'Georgia, serif' }}>404</p>
      <h1 style={{ fontSize: '1.4rem', fontWeight: 600, margin: 0 }}>
        We couldn&apos;t find that page
      </h1>
      <p style={{ maxWidth: 440, color: '#666', margin: 0 }}>
        The item you&apos;re looking for doesn&apos;t exist or may have been moved.
      </p>
      <div style={{ display: 'flex', gap: '0.75rem', marginTop: '0.5rem', flexWrap: 'wrap', justifyContent: 'center' }}>
        <Link
          href="/listing"
          style={{
            padding: '0.7rem 1.4rem',
            background: '#111',
            color: '#fff',
            borderRadius: 6,
            textDecoration: 'none',
            fontWeight: 600,
          }}
        >
          Browse all products
        </Link>
        <Link
          href="/"
          style={{
            padding: '0.7rem 1.4rem',
            border: '1px solid #ccc',
            borderRadius: 6,
            textDecoration: 'none',
            fontWeight: 600,
            color: '#111',
          }}
        >
          Go home
        </Link>
      </div>
    </div>
  )
}
