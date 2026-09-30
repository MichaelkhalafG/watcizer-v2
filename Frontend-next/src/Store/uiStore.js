'use client'
import { createContext, createElement, useContext, useState } from 'react'
import { createStore, useStore } from 'zustand'

// ONE STORE PER REQUEST (S-AR stage 1, 2026-10-01). This used to be a module-level `create()`
// store starting at language 'en' — and zustand renders the SERVER (and the hydration pass) from
// the store's INITIAL state, so every page's server HTML was English and flipped to Arabic only
// after mount: Google never saw an Arabic page. A module-level store cannot start in the request's
// language either: on the server it is shared by every concurrent request.
//
// So <UIStoreProvider language=…> (in app/providers.jsx, fed by the root layout from the request:
// `/ar/…` URL, else the wz-lang cookie) creates the store with that language, and `useUIStore`
// keeps its old signature — `useUIStore()` / `useUIStore(selector)` — reading the store from
// context. The browser has exactly one provider, so its store is also reachable statically through
// `useUIStore.getState()` for the few non-render callers.

// NOTE: filters/gradesFilters/offersFilters are seeded with the SAME shapes
// MyProvider used (not the spec's bare {} / []), because consumers access
// filters.categories.length, filters.price[0], etc. — bare defaults would crash.
const initializer = (language) => (set) => ({
  language,
  // Persist language to a COOKIE (not localStorage) so the server can read it on the next request
  // (middleware.js → the root layout) and render that language on the first paint.
  setLanguage: (lang) => {
    set({ language: lang })
    if (typeof document !== 'undefined') {
      document.cookie = `wz-lang=${lang};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`
    }
  },

  currentPage: 1,
  setCurrentPage: (page) => set({ currentPage: page }),

  // Search results setter used by SearchBox (was MyProvider's setFilteredProducts).
  filteredProducts: [],
  setFilteredProducts: (v) =>
    set((s) => ({ filteredProducts: typeof v === 'function' ? v(s.filteredProducts) : v })),

  filters: {
    categories: [],
    brands: [],
    subTypes: [],
    genders: [],
    offers: false,
    price: [0, 99999999],
    // Extended filter dimensions (id-based). Colors are matched via the
    // product's dial_colors/band_colors pivot arrays; material/movement are
    // scalar product columns.
    dialColors: [],
    bandColors: [],
    materials: [],
    movements: [],
    shapes: [],
    displayTypes: [],
    grades: [],
  },
  // Accept either a plain object or a functional updater (prev) => next, so
  // consumers like SideBar that do setFilters((prev) => ({ ...prev })) work.
  setFilters: (f) => set((s) => ({ filters: typeof f === 'function' ? f(s.filters) : f })),

  gradesFilters: {
    categories: [],
    brands: [],
    subTypes: [],
    grades: [],
    price: [0, 6000],
  },
  setGradesFilters: (f) => set({ gradesFilters: f }),

  offersFilters: {
    categories: [],
    price: [0, 6000],
    ratings: [],
  },
  setOffersFilters: (f) => set({ offersFilters: f }),
})

const UIStoreContext = createContext(null)

// Outside any provider (a test, an error page rendered above Providers): an English store.
const fallbackStore = createStore(initializer('en'))
let browserStore = null

export function UIStoreProvider({ language = 'en', children }) {
  const [store] = useState(() => {
    const s = createStore(initializer(language === 'ar' ? 'ar' : 'en'))
    if (typeof window !== 'undefined') browserStore = s
    return s
  })
  return createElement(UIStoreContext.Provider, { value: store }, children)
}

export function useUIStore(selector) {
  return useStore(useContext(UIStoreContext) ?? fallbackStore, selector)
}

// The store itself, for code that must read the state at CALL time rather than render time (a router
// push right after setLanguage — see Hooks/useLocaleRouter.js).
export function useUIStoreApi() {
  return useContext(UIStoreContext) ?? fallbackStore
}

// Static access for non-render code — the browser's one store (never a server request's).
useUIStore.getState = () => (browserStore ?? fallbackStore).getState()
