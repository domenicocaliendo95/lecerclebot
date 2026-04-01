export interface User {
  id: number
  name: string
  phone: string
  is_fit: boolean
  fit_rating: string | null
  self_level: string | null
  age: number | null
  elo_rating: number
  matches_played: number
  matches_won: number
  is_elo_established: boolean
  preferred_slots: string[] | null
  created_at: string
}

export interface Booking {
  id: number
  player1_id: number
  player2_id: number | null
  booking_date: string
  start_time: string
  end_time: string
  price: number
  is_peak: boolean
  status: 'pending_match' | 'confirmed' | 'cancelled' | 'completed'
  gcal_event_id: string | null
  payment_status_p1: string
  payment_status_p2: string
  created_at: string
  player1?: User
  player2?: User
}

export interface BotSession {
  id: number
  phone: string
  state: string
  persona: string | null
  profile: Record<string, unknown> | null
  history: { role: string; content: string }[]
  created_at: string
  updated_at: string
}

export interface MatchResult {
  id: number
  booking_id: number
  winner_id: number | null
  score: string | null
  player1_elo_before: number
  player1_elo_after: number
  player2_elo_before: number
  player2_elo_after: number
  player1_confirmed: boolean
  player2_confirmed: boolean
  confirmed_at: string | null
  created_at: string
  booking?: Booking
  winner?: User
}

export interface PricingRule {
  id: number
  label: string | null
  day_of_week: number | null
  specific_date: string | null
  start_time: string
  end_time: string
  duration_minutes: number | null
  price: number | null
  price_per_hour: number | null
  is_peak: boolean
  is_active: boolean
  priority: number
}

export interface DashboardStats {
  bookings_today: number
  bookings_today_trend: number
  revenue_today: number
  revenue_today_trend: number
  total_players: number
  new_players_week: number
  pending_matches: number
}

export interface WeeklyBookingData {
  date: string
  label: string
  confirmed: number
  pending: number
  completed: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}
