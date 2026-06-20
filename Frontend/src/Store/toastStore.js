import { create } from 'zustand'

export const useToastStore = create((set) => ({
  open: false,
  type: 'success',
  message: '',
  showToast: (message, type = 'success') => set({ open: true, message, type }),
  hideToast: () => set({ open: false }),
}))
