import {
  CalendarCheck,
  Euro,
  Users,
  Clock,
  TrendingUp,
  TrendingDown,
  Loader2,
  type LucideIcon,
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
      {/* Stats */}
      {statsLoading || !stats ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[...Array(4)].map((_, i) => (
            <Card key={i} className="rounded-xl">
              <CardContent className="pt-6 flex items-center justify-center h-28">
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
            accent="emerald"
          />
          <StatCard
            title="Incasso oggi"
            value={`€${stats.revenue_today}`}
            trend={stats.revenue_today_trend}
            icon={Euro}
            accent="blue"
          />
          <StatCard
            title="Giocatori totali"
            value={stats.total_players}
            subtitle={`+${stats.new_players_week} questa settimana`}
            icon={Users}
            accent="amber"
          />
          <StatCard
            title="Match in attesa"
            value={stats.pending_matches}
            icon={Clock}
            accent="violet"
          />
        </div>
      )}

      {/* Chart + Table */}
      <div className="grid gap-6 lg:grid-cols-5">
        {/* Weekly chart */}
        <Card className="lg:col-span-3 rounded-xl shadow-sm">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-600">Prenotazioni ultimi 7 giorni</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-72">
              {chartLoading || !weeklyData ? (
                <div className="loading-center h-full">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                  <span className="text-xs text-muted-foreground">Caricamento grafico...</span>
                </div>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={weeklyData} barCategoryGap="20%">
                    <CartesianGrid strokeDasharray="3 3" stroke="oklch(0.9 0 0 / 60%)" vertical={false} />
                    <XAxis
                      dataKey="label"
                      tick={{ fill: 'oklch(0.55 0 0)', fontSize: 12 }}
                      axisLine={false}
                      tickLine={false}
                    />
                    <YAxis
                      allowDecimals={false}
                      tick={{ fill: 'oklch(0.55 0 0)', fontSize: 12 }}
                      axisLine={false}
                      tickLine={false}
                    />
                    <Tooltip
                      contentStyle={{
                        borderRadius: '10px',
                        border: '1px solid oklch(0.92 0 0)',
                        backgroundColor: 'white',
                        boxShadow: '0 4px 12px oklch(0 0 0 / 6%)',
                        fontSize: '13px',
                      }}
                    />
                    <Legend
                      wrapperStyle={{ fontSize: '12px', paddingTop: '8px' }}
                    />
                    <Bar dataKey="confirmed" name="Confermate" fill="#10b981" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="pending" name="In attesa" fill="#f59e0b" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="completed" name="Completate" fill="#38bdf8" radius={[6, 6, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Today schedule */}
        <Card className="lg:col-span-2 rounded-xl shadow-sm">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-600">Prenotazioni di oggi</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2.5">
            {todayLoading ? (
              <div className="loading-center">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                <span className="text-xs text-muted-foreground">Caricamento...</span>
              </div>
            ) : todayBookings.length === 0 ? (
              <div className="py-10 text-center">
                <CalendarCheck className="mx-auto h-8 w-8 text-slate-300 mb-2" />
                <p className="text-sm text-muted-foreground">
                  Nessuna prenotazione per oggi.
                </p>
              </div>
            ) : (
              todayBookings.map((b) => {
                const st = statusMap[b.status] ?? statusMap.confirmed
                return (
                  <div
                    key={b.id}
                    className="flex items-center justify-between rounded-lg border border-slate-100 bg-white p-3 transition-colors hover:border-slate-200"
                  >
                    <div className="min-w-0">
                      <p className="text-[13px] font-medium truncate text-slate-800">
                        {b.player1?.name ?? 'Giocatore'}
                        {b.player2?.name ? ` vs ${b.player2.name}` : ''}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {b.start_time} – {b.end_time}
                        {b.is_peak && ' · Peak'}
                      </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="text-[13px] font-semibold text-slate-700">€{b.price}</span>
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
  accent,
}: {
  title: string
  value: string | number
  trend?: number
  subtitle?: string
  icon: LucideIcon
  accent: 'emerald' | 'blue' | 'amber' | 'violet'
}) {
  const accentStyles: Record<string, { card: string; iconBg: string; iconText: string }> = {
    emerald: {
      card: 'stat-card-emerald',
      iconBg: 'bg-emerald-50',
      iconText: 'text-emerald-600',
    },
    blue: {
      card: 'stat-card-blue',
      iconBg: 'bg-blue-50',
      iconText: 'text-blue-600',
    },
    amber: {
      card: 'stat-card-amber',
      iconBg: 'bg-amber-50',
      iconText: 'text-amber-600',
    },
    violet: {
      card: 'stat-card-violet',
      iconBg: 'bg-violet-50',
      iconText: 'text-violet-600',
    },
  }

  const s = accentStyles[accent]

  return (
    <Card className={`rounded-xl shadow-sm ${s.card}`}>
      <CardContent className="pt-5 pb-4">
        <div className="flex items-center justify-between mb-3">
          <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">{title}</p>
          <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${s.iconBg}`}>
            <Icon className={`h-4 w-4 ${s.iconText}`} />
          </div>
        </div>
        <div className="flex items-baseline gap-2">
          <p className="text-2xl font-bold tracking-tight text-slate-900">{value}</p>
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
