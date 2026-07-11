'use client'
export default function MainError({ error, reset }) {
  return (
    <div className="wz-error">
      <div className="wz-error-inner">
        <h1 className="wz-error-title">Something went wrong</h1>
        <p className="wz-error-text">We couldn&apos;t load this page. Please try again or go back to browsing.</p>
        <div className="wz-error-actions">
          <button onClick={() => reset()} className="wz-error-retry">Try Again</button>
          {/* Hard navigation (not next/link) is intentional in an error boundary: a
              full page load fully resets the crashed React tree. */}
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
          <a href="/" className="wz-error-home">Back to Home</a>
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
          <a href="/listing" className="wz-error-browse">Browse Collection</a>
        </div>
      </div>
    </div>
  )
}
