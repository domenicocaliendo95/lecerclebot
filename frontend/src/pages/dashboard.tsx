import {
  CalendarCheck,
  Euro,
  Users,
  Clock,
  TrendingUp,
  TrendingDown,
  Loader2,
} from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts'
import { useApi } from '@/hooks/use-api'
import type { Booking, DashboardStats, WeeklyBookingData } from '@/types/api'

const statusMap: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive' }> = {
  confirmed: { label: 'Confermata', variant: 'default' },
  pending_match: { label: 'In attesa', variant: 'secondary' },
  completed: { label: 'Completata', variant: 'outline' },
  cancelled: { label: 'Annullata', variant: 'destructive' },
}

export function Dashboard() {
  const { data: stats, loading: statsLoading } = useApi<DashboardStats>('/admin/dashboard/stats')
  const { data: weeklyData, loading: chartLoading } = useApi<WeeklyBookingData[]>('/admin/dashboard/weekly-chart')
  const { data: todayResponse, loading: todayLoading } = useApi<{ data: Booking[] }>('/admin/bookings/today')

  const todayBookings = todayResponse?.data ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p className="text-muted-foreground">
          Panoramica del circolo — {new Date().toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' })}
        </p>
      </div>

      {/* Stats */}
      {statsLoading || !stats ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[...Array(4)].map((_, i) => (
            <Card key={i}>
              <CardContent className="pt-6 flex items-center justify-center h-24">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard
            title="Prenotazioni oggi"
            value={stats.bookings_today}
            trend={stats.bookings_today_trend}
            icon={CalendarCheck}
          />
          <StatCard
            title="Incasso oggi"
            value={`€${stats.revenue_today}`}
            trend={stats.revenue_today_trend}
            icon={Euro}
          />
          <StatCard
            title="Giocatori totali"
            value={stats.total_players}
            subtitle={`+${stats.new_players_week} questa settimana`}
            icon={Users}
          />
          <StatCard
            title="Match in attesa"
            value={stats.pending_matches}
            icon={Clock}
          />
        </div>
      )}

      {/* Chart + Table */}
      <div className="grid gap-6 lg:grid-cols-5">
        {/* Weekly chart */}
        <Card className="lg:col-span-3">
          <CardHeader>
            <CardTitle className="text-base">Prenotazioni ultimi 7 giorni</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-72">
              {chartLoading || !weeklyData ? (
                <div className="flex h-full items-center justify-center">
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={weeklyData} barCategoryGap="20%">
                    <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                    <XAxis dataKey="label" className="text-xs" tick={{ fill: 'var(--color-muted-foreground)' }} />
                    <YAxis allowDecimals={false} className="text-xs" tick={{ fill: 'var(--color-muted-foreground)' }} />
                    <Tooltip
                      contentStyle={{
                        borderRadius: '8px',
                        border: '1px solid var(--color-border)',
                        backgroundColor: 'var(--color-card)',
                      }}
                    />
                    <Legend />
                    <Bar dataKey="confirmed" name="Confermate" fill="#10b981" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="pending" name="In attesa" fill="#f59e0b" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="completed" name="Completate" fill="#38bdf8" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Today schedule */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-base">Prenotazioni di oggi</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {todayLoading ? (
              <div className="flex justify-center py-8">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            ) : todayBookings.length === 0 ? (
              <p className="text-sm text-muted-foreground py-8 text-center">
                Nessuna prenotazione per oggi.
              </p>
            ) : (
              todayBookings.map((b) => {
                const st = statusMap[b.status] ?? statusMap.confirmed
                return (
                  <div
                    key={b.id}
                    className="flex items-center justify-between rounded-lg border p-3"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-medium truncate">
                        {b.player1?.name ?? 'Giocatore'}
                        {b.player2?.name ? ` vs ${b.player2.name}` : ''}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {b.start_time} – {b.end_time}
                        {b.is_peak && ' · Peak'}
                      </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="text-sm font-medium">€{b.price}</span>
                      <Badge variant={st.variant}>{st.label}</Badge>
                    </div>
                  </div>
                )
              })
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function StatCard({
  title,
  value,
  trend,
  subtitle,
  icon: Icon,
}: {
  title: string
  value: string | number
  trend?: number
  subtitle?: string
  icon: typeof CalendarCheck
}) {
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="flex items-center justify-between">
          <p className="text-sm font-medium text-muted-foreground">{title}</p>
          <Icon className="h-4 w-4 text-muted-foreground" />
        </div>
        <div className="mt-2 flex items-baseline gap-2">
          <p className="text-2xl font-bold">{value}</p>
          {trend !== undefined && (
            <span
              className={`flex items-center text-xs font-medium ${
                trend >= 0 ? 'text-emerald-600' : 'text-red-500'
              }`}
            >
              {trend >= 0 ? (
                <TrendingUp className="mr-0.5 h-3 w-3" />
              ) : (
                <TrendingDown className="mr-0.5 h-3 w-3" />
              )}
              {Math.abs(trend)}%
            </span>
          )}
        </div>
        {subtitle && (
          <p className="mt-1 text-xs text-muted-foreground">{subtitle}</p>
        )}
      </CardContent>
    </Card>
  )
}
