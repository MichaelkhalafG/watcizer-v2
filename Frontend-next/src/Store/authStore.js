import { create } from 'zustand'
import { ASSET_BASE } from '../lib/env'

// Auth is JWT-based; the token lives in sessionStorage (the api.jsx interceptor
// reads it as `Authorization: Bearer`). This store keeps the legacy `userId` /
// `setUserId` / `clearAuth` API (read across the app) and adds a richer
// `login` / `logout` / `setUser` surface plus `isAuthenticated`.

// SSR guard: on the server there is no sessionStorage, so every read returns null
// and the store initializes logged-out. The client rehydrates on mount (see
// <AuthHydrator/> in app/providers.jsx) once real sessionStorage is available.
const ss = (k) => (typeof window === 'undefined' ? null : sessionStorage.getItem(k))

// Build a full avatar URL from the stored filename (matches the legacy flow).
const avatarUrl = (image) =>
  image ? (/^https?:\/\//i.test(image) ? image : `${ASSET_BASE}/Uploads_Images/User/${image}`) : ''

const readUser = () => {
  const id = ss('user_id')
  if (!id) return null
  return {
    id,
    first_name: ss('first_name') || '',
    last_name: ss('last_name') || '',
    email: ss('email') || '',
    phone_number: ss('phone_number') || '',
    image: ss('image') || '',
  }
}

const persist = (data) => {
  sessionStorage.setItem('token', data.token)
  sessionStorage.setItem('user_id', data.id)
  sessionStorage.setItem('first_name', data.first_name || '')
  sessionStorage.setItem('last_name', data.last_name || '')
  sessionStorage.setItem('email', data.email || '')
  sessionStorage.setItem('phone_number', data.phone_number || '')
  sessionStorage.setItem('image', avatarUrl(data.image))
}

const clearStorage = () => {
  ;['token', 'user_id', 'first_name', 'last_name', 'email', 'phone_number', 'image'].forEach((k) =>
    sessionStorage.removeItem(k),
  )
}

export const useAuthStore = create((set, get) => ({
  userId: ss('user_id') || null,
  token: ss('token') || null,
  user: readUser(),
  isAuthenticated: !!ss('token'),

  // Re-read sessionStorage on the client after hydration and sync state. Called
  // by <AuthHydrator/> so SSR (logged-out) and the client (real session) agree
  // without a hydration mismatch.
  rehydrate: () =>
    set({
      userId: ss('user_id') || null,
      token: ss('token') || null,
      user: readUser(),
      isAuthenticated: !!ss('token'),
    }),

  setUserId: (id) => {
    sessionStorage.setItem('user_id', id)
    set({ userId: id })
  },

  // Full login from a /login or /register response (user object incl. token).
  login: (data) => {
    persist(data)
    set({
      token: data.token,
      userId: String(data.id),
      user: { ...readUser(), ...data, image: avatarUrl(data.image) },
      isAuthenticated: true,
    })
  },

  setUser: (user) => set({ user }),

  // Merge a partial update into the current user and persist the individually
  // tracked keys, so a reload keeps the change and any component still reading
  // sessionStorage stays in sync. `image` is expected as a ready-to-use URL.
  updateUser: (partial) => {
    const current = get().user || readUser() || {}
    const next = { ...current, ...partial }
    const keys = ['first_name', 'last_name', 'email', 'phone_number', 'image']
    keys.forEach((k) => {
      if (k in partial) sessionStorage.setItem(k, next[k] || '')
    })
    set({ user: next })
  },

  // Expose the same avatar-URL builder the store uses internally.
  avatarUrl,

  logout: () => {
    clearStorage()
    set({ token: null, userId: null, user: null, isAuthenticated: false })
  },

  // Backward-compatible alias used by existing components (Header logout, etc.).
  clearAuth: () => get().logout(),
}))
