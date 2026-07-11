'use client'
export default function GlobalError({ error, reset }) {
  return (
    <div className="wz-error">
      <div className="wz-error-inner">
        <h1 className="wz-error-title">Something went wrong</h1>
        <p className="wz-error-text">We&apos;re sorry — an unexpected error occurred. Please try again.</p>
        <div className="wz-error-actions">
          <button onClick={() => reset()} className="wz-error-retry">Try Again</button>
          {/* Hard navigation (not next/link) is intentional in an error boundary: a
              full page load fully resets the crashed React tree. */}
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
          <a href="/" className="wz-error-home">Back to Home</a>
        </div>
      </div>
    </div>
  )
}
