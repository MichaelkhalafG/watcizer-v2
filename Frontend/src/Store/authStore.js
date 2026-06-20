import { create } from 'zustand'

export const useAuthStore = create((set) => ({
  userId: sessionStorage.getItem('user_id') || null,
  setUserId: (id) => {
    sessionStorage.setItem('user_id', id)
    set({ userId: id })
  },
  clearAuth: () => {
    sessionStorage.removeItem('user_id')
    sessionStorage.removeItem('token')
    set({ userId: null })
  },
}))
