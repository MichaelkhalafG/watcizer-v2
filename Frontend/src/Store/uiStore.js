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
    price: [0, 6000],
  },
  setFilters: (f) => set({ filters: f }),

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
