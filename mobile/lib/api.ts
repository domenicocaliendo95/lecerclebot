import axios, { AxiosInstance } from 'axios';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'lecercle.auth.token';

const baseURL =
  (Constants.expoConfig?.extra?.apiBaseUrl as string | undefined) ??
  'https://bot.lecercleclub.it/api/v1/app';

export const api: AxiosInstance = axios.create({
  baseURL,
  timeout: 15000,
  headers: { Accept: 'application/json' },
});

// Iniettiamo il token Sanctum a ogni request
api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export async function setAuthToken(token: string) {
  await SecureStore.setItemAsync(TOKEN_KEY, token, {
    keychainAccessible: SecureStore.WHEN_UNLOCKED,
  });
}

export async function getAuthToken(): Promise<string | null> {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export async function clearAuthToken() {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

// ── Endpoint helpers ──────────────────────────────────────────────────

export type RequestOtpResponse = {
  otp_id: number;
  expires_at: string;
  masked_phone: string;
  resend_available_at: string;
};

export type VerifyOtpResponse = {
  token: string;
  user: AppUser;
  is_new: boolean;
  needs_app_onboarding: boolean;
};

export type AppUser = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  avatar_url: string | null;
  bio: string | null;
  birthdate: string | null;
  is_fit: boolean;
  fit_rating: string | null;
  self_level: number | null;
  elo_rating: number;
  matches_played: number;
  matches_won: number;
  preferred_slots: string[] | null;
  notification_preferences: Record<string, boolean> | null;
  privacy_profile: 'public' | 'club_only' | 'friends_only';
  show_in_matchmaking: boolean;
  app_onboarded_at: string | null;
};

export const auth = {
  requestOtp: (phone: string) =>
    api.post<RequestOtpResponse>('/auth/request-otp', { phone }).then((r) => r.data),
  verifyOtp: (phone: string, code: string, deviceName?: string) =>
    api
      .post<VerifyOtpResponse>('/auth/verify-otp', { phone, code, device_name: deviceName })
      .then((r) => r.data),
  requestOtpEmail: (phone: string, email: string) =>
    api.post('/auth/request-otp-email', { phone, email }).then((r) => r.data),
  logout: () => api.post('/auth/logout').then((r) => r.data),
};

export type Club = {
  id: number;
  slug: string;
  name: string;
  tagline: string | null;
  logo_url: string | null;
  primary_color: string;
  secondary_color: string;
  accent_color: string;
  address: string | null;
  phone: string | null;
  email: string | null;
  timezone: string;
};

export const club = {
  get: () => api.get<Club>('/club').then((r) => r.data),
};
