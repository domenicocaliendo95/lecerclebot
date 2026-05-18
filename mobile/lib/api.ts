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

// ── Types ──────────────────────────────────────────────────────────────

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
  is_fit: boolean | null;
  fit_rating: string | null;
  self_level: number | null;
  elo_rating: number | null;
  matches_played: number | null;
  matches_won: number | null;
  preferred_slots: string[] | null;
  notification_preferences: Record<string, boolean> | null;
  privacy_profile: 'public' | 'club_only' | 'friends_only' | null;
  show_in_matchmaking: boolean | null;
  app_onboarded_at: string | null;
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

export type BookingOpponent = {
  id: number;
  name: string;
  avatar_url: string | null;
  elo_rating: number | null;
};

export type AppBooking = {
  id: number;
  date: string;             // YYYY-MM-DD
  start_time: string;       // HH:mm
  end_time: string;         // HH:mm
  duration_minutes: number;
  price: number;
  is_peak: boolean;
  status: 'confirmed' | 'pending_match' | 'cancelled';
  created_via: 'bot_whatsapp' | 'app' | 'admin_panel';
  notes: string | null;
  me_role: 'player1' | 'player2';
  opponent: BookingOpponent | null;
  opponent_name_text: string | null;
  opponent_confirmed: boolean;
  starts_at_iso: string;
};

export type LeaderboardEntry = {
  rank: number;
  id: number;
  name: string;
  avatar_url: string | null;
  elo_rating: number;
  matches_played: number;
  matches_won: number;
  fit_rating: string | null;
  is_me: boolean;
};

export type LeaderboardResponse = {
  data: LeaderboardEntry[];
  me: {
    rank: number;
    elo_rating: number | null;
    matches_played: number | null;
    matches_won: number | null;
  };
};

// ── Endpoint helpers ───────────────────────────────────────────────────

export const auth = {
  requestOtp: (phone: string) =>
    api.post<RequestOtpResponse>('/auth/request-otp', { phone }).then((r) => r.data),
  verifyOtp: (phone: string, code: string, deviceName?: string) =>
    api.post<VerifyOtpResponse>('/auth/verify-otp', { phone, code, device_name: deviceName })
      .then((r) => r.data),
  requestOtpEmail: (phone: string, email: string) =>
    api.post('/auth/request-otp-email', { phone, email }).then((r) => r.data),
  logout: () => api.post('/auth/logout').then((r) => r.data),
};

export const club = {
  get: () => api.get<Club>('/club').then((r) => r.data),
};

export const me = {
  get: () => api.get<AppUser>('/me').then((r) => r.data),
  update: (data: Partial<Pick<AppUser,
    'name' | 'bio' | 'birthdate' | 'is_fit' | 'fit_rating' | 'self_level' |
    'preferred_slots' | 'privacy_profile' | 'show_in_matchmaking'>>) =>
    api.patch<AppUser>('/me', data).then((r) => r.data),
  updateNotificationPreferences: (prefs: Record<string, boolean>) =>
    api.patch<{ notification_preferences: Record<string, boolean> }>('/me/notification-preferences', prefs)
      .then((r) => r.data),
  completeOnboarding: () =>
    api.post<{ ok: true }>('/me/complete-onboarding').then((r) => r.data),
  uploadAvatar: async (uri: string) => {
    const form = new FormData();
    const filename = uri.split('/').pop() ?? 'avatar.jpg';
    const match = /\.(\w+)$/.exec(filename);
    const type = match ? `image/${match[1].toLowerCase()}` : 'image/jpeg';
    form.append('avatar', { uri, name: filename, type } as any);
    const res = await api.post<{ avatar_url: string }>('/me/avatar', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      transformRequest: (data) => data, // axios di default JSON-ifica
    });
    return res.data;
  },
  deleteAvatar: () => api.delete('/me/avatar').then((r) => r.data),
  registerDevice: (data: { expo_push_token: string; platform: 'ios' | 'android'; device_name?: string; app_version?: string }) =>
    api.post('/me/devices', data).then((r) => r.data),
  delete: () => api.delete('/me').then((r) => r.data),
};

export type AvailabilitySlot = {
  time: string;       // HH:mm
  end_time: string;   // HH:mm
  price: number;
  available: boolean;
  is_past: boolean;
};

export type AvailabilityResponse = {
  date: string;
  duration_minutes: number;
  slots: AvailabilitySlot[];
};

export type BookingType = 'con_avversario' | 'matchmaking' | 'sparapalline';

export type CreateBookingPayload = {
  date: string;
  start_time: string;
  duration_minutes: 60 | 90 | 120;
  type: BookingType;
  opponent_user_id?: number | null;
  opponent_name_text?: string | null;
  payment_method?: 'online' | 'in_loco';
  notes?: string | null;
};

export type PlayerSearchResult = {
  id: number;
  name: string;
  avatar_url: string | null;
  elo_rating: number | null;
  fit_rating: string | null;
};

export const bookings = {
  list: (params?: { status?: 'upcoming' | 'past' | 'all'; from?: string; to?: string; page?: number }) =>
    api.get<{ data: AppBooking[]; meta: any }>('/bookings', { params }).then((r) => r.data),
  next: () =>
    api.get<{ data: AppBooking | null }>('/bookings/next').then((r) => r.data.data),
  show: (id: number) =>
    api.get<{ data: AppBooking }>(`/bookings/${id}`).then((r) => r.data.data),
  cancel: (id: number) =>
    api.delete<{ ok: true }>(`/bookings/${id}`).then((r) => r.data),
  availability: (date: string, durationMinutes: 60 | 90 | 120 = 60) =>
    api.get<AvailabilityResponse>('/bookings/availability', {
      params: { date, duration_minutes: durationMinutes },
    }).then((r) => r.data),
  create: (payload: CreateBookingPayload) =>
    api.post<{ data: AppBooking }>('/bookings', payload).then((r) => r.data.data),
};

export const players = {
  search: (q: string) =>
    api.get<{ data: PlayerSearchResult[] }>('/players/search', { params: { q } }).then((r) => r.data.data),
};

export type PendingResult = {
  booking_id: number;
  date: string;
  start_time: string;
  end_time: string;
  opponent: { id: number; name: string; avatar_url: string | null } | null;
  opponent_name_text: string | null;
  is_tracked: boolean;
};

export type SubmittedResult = {
  booking_id: number;
  date: string;
  opponent_name: string | null;
  winner_id: number | null;
  i_won: boolean;
  score: string | null;
  my_confirmed: boolean;
  opponent_confirmed: boolean;
  finalized: boolean;
  elo_delta: number | null;
};

export const matchResults = {
  pending: () =>
    api.get<{ data: PendingResult[] }>('/match-results/pending').then((r) => r.data.data),
  list: () =>
    api.get<{ data: SubmittedResult[]; meta: any }>('/match-results').then((r) => r.data),
  submit: (bookingId: number, payload: { outcome: 'won' | 'lost' | 'not_played'; score?: string }) =>
    api.post<{ data: SubmittedResult; warning?: string }>(`/match-results/${bookingId}`, payload).then((r) => r.data),
};

// ── Player public profile ──────────────────────────────────────────────

export type PublicPlayer = {
  id: number;
  name: string;
  bio: string | null;
  avatar_url: string | null;
  elo_rating: number | null;
  matches_played: number | null;
  matches_won: number | null;
  fit_rating: string | null;
  self_level: number | null;
  is_me: boolean;
  head_to_head: { played: number; me_wins: number; other_wins: number };
};

export type RecentMatch = {
  date: string;
  opponent_name: string | null;
  opponent_avatar_url: string | null;
  won: boolean;
  score: string | null;
  elo_delta: number | null;
};

export const playersProfile = {
  show: (id: number) =>
    api.get<{ data: PublicPlayer }>(`/players/${id}`).then((r) => r.data.data),
  recentMatches: (id: number) =>
    api.get<{ data: RecentMatch[] }>(`/players/${id}/recent-matches`).then((r) => r.data.data),
};

// ── Activity feed ───────────────────────────────────────────────────────

export type FeedItem =
  | {
      type: 'match_won';
      happened_at: string;
      winner: { id: number; name: string } | null;
      loser: { id: number; name: string } | null;
      score: string | null;
      avatar_url: string | null;
    }
  | {
      type: 'booking_created';
      happened_at: string;
      player: { id: number; name: string } | null;
      opponent_name: string | null;
      date: string;
      start_time: string;
      avatar_url: string | null;
    };

export const feed = {
  get: () => api.get<{ data: FeedItem[] }>('/feed').then((r) => r.data.data),
};

// ── Feedback ────────────────────────────────────────────────────────────

export const feedback = {
  submit: (payload: { rating: number; comment?: string; type?: 'post_match' | 'spontaneous'; booking_id?: number }) =>
    api.post<{ data: { id: number; rating: number; comment: string | null; created_at: string } }>('/feedback', payload)
      .then((r) => r.data.data),
};

export const leaderboard = {
  get: () => api.get<LeaderboardResponse>('/leaderboard').then((r) => r.data),
};

export const pricingRules = {
  list: () => api.get<{ data: any[] }>('/pricing-rules').then((r) => r.data.data),
};
