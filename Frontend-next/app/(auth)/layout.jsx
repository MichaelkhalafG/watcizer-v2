// Full-bleed auth layout — NO header/footer (mirrors AUTH_ROUTES in Frontend
// App.jsx, where /login /register /forgot-password /reset-password /auth/callback
// render without the global chrome). Server component.
export default function AuthLayout({ children }) {
  return (
    <main id="main-content" tabIndex={-1}>
      {children}
    </main>
  )
}
