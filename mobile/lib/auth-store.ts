import { create } from 'zustand';

import { AppUser, auth, clearAuthToken, getAuthToken, me, setAuthToken } from './api';

type AuthState = {
  user: AppUser | null;
  token: string | null;
  isHydrated: boolean;
  // actions
  hydrate: () => Promise<void>;
  setAuth: (token: string, user: AppUser) => Promise<void>;
  setUser: (user: AppUser) => void;
  refreshUser: () => Promise<void>;
  signOut: () => Promise<void>;
};

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  token: null,
  isHydrated: false,

  hydrate: async () => {
    const token = await getAuthToken();
    if (!token) {
      set({ token: null, user: null, isHydrated: true });
      return;
    }
    set({ token });
    // Fetch /me per rinfrescare i dati utente. Se fallisce (401 token invalido)
    // facciamo logout silenzioso.
    try {
      const user = await me.get();
      set({ user, isHydrated: true });
    } catch {
      await clearAuthToken();
      set({ token: null, user: null, isHydrated: true });
    }
  },

  setAuth: async (token, user) => {
    await setAuthToken(token);
    set({ token, user });
  },

  setUser: (user) => set({ user }),

  refreshUser: async () => {
    try {
      const user = await me.get();
      set({ user });
    } catch {
      // ignora errori temporanei di rete
    }
  },

  signOut: async () => {
    try { await auth.logout(); } catch { /* ignora errori di rete */ }
    await clearAuthToken();
    set({ token: null, user: null });
  },
}));
