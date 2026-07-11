'use client'
export default function AuthError({ error, reset }) {
  return (
    <div className="wz-error wz-error--auth">
      <div className="wz-error-inner">
        <h1 className="wz-error-title">Something went wrong</h1>
        <p className="wz-error-text">We couldn&apos;t load this page. Please try again.</p>
        <button onClick={() => reset()} className="wz-error-retry">Try Again</button>
      </div>
    </div>
  )
}
