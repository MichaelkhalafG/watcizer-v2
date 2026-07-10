import { create } from 'zustand'

// NOTE: filters/gradesFilters/offersFilters are seeded with the SAME shapes
// MyProvider used (not the spec's bare {} / []), because consumers access
// filters.categories.length, filters.price[0], etc. — bare defaults would crash.
export const useUIStore = create((set) => ({
  language: 'en',
  setLanguage: (lang) => set({ language: lang }),

  currentPage: 1,
  setCurrentPage: (page) => set({ currentPage: page }),

  // Wishlist entries (formerly MyProvider state). Kept as cross-cutting client
  // state so every heart button across the app stays in sync. The fetch/refresh
  // effect lives in <AppStateBridge/>; the add/remove toggle lives in the
  // useWishlist() hook — both write here. setWishList accepts a value or a
  // functional updater (prev) => next, matching the old useState setter.
  wishList: [],
  setWishList: (v) =>
    set((s) => ({ wishList: typeof v === 'function' ? v(s.wishList) : v })),

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
  setFilters: (f) =>
    set((s) => ({ filters: typeof f === 'function' ? f(s.filters) : f })),

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
}))
