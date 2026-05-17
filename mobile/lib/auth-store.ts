import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';

import { AppUser, auth, clearAuthToken, getAuthToken, setAuthToken } from './api';

type AuthState = {
  user: AppUser | null;
  token: string | null;
  isHydrated: boolean;
  // actions
  hydrate: () => Promise<void>;
  setAuth: (token: string, user: AppUser) => Promise<void>;
  setUser: (user: AppUser) => void;
  signOut: () => Promise<void>;
};

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  token: null,
  isHydrated: false,

  hydrate: async () => {
    const token = await getAuthToken();
    set({ token, isHydrated: true });
    // TODO: optional: fetch /me to refresh user
  },

  setAuth: async (token, user) => {
    await setAuthToken(token);
    set({ token, user });
  },

  setUser: (user) => set({ user }),

  signOut: async () => {
    try {
      await auth.logout();
    } catch {
      // ignora errori di rete: forziamo logout locale comunque
    }
    await clearAuthToken();
    set({ token: null, user: null });
  },
}));
