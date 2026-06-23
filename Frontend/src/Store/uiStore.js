import { create } from 'zustand'

// NOTE: filters/gradesFilters/offersFilters are seeded with the SAME shapes
// MyProvider used (not the spec's bare {} / []), because consumers access
// filters.categories.length, filters.price[0], etc. — bare defaults would crash.
export const useUIStore = create((set) => ({
  language: 'en',
  setLanguage: (lang) => set({ language: lang }),

  currentPage: 1,
  setCurrentPage: (page) => set({ currentPage: page }),

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
