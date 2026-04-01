import { useState, useMemo, useEffect, useCallback } from 'react'
import {
  ChevronLeft,
  ChevronRight,
  Plus,
  Loader2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { apiFetch } from '@/hooks/use-api'
import type { Booking } from '@/types/api'

// ── Helpers ──────────────────────────────────────────────────────────

const HOURS = Array.from({ length: 15 }, (_, i) => i + 8) // 08–22
const HOUR_HEIGHT = 80

function timeToMinutes(time: string): number {
  const [h, m] = time.split(':').map(Number)
  return h * 60 + m
}

function formatDate(date: Date): string {
  return date.toISOString().slice(0, 10)
}

function addDays(date: Date, days: number): Date {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d
}

function startOfWeek(date: Date): Date {
  const d = new Date(date)
  const day = d.getDay()
  const diff = d.getDate() - day + (day === 0 ? -6 : 1)
  d.setDate(diff)
  return d
}

const statusColor: Record<string, string> = {
  confirmed: 'bg-emerald-500/15 border-emerald-500/30 text-emerald-700 dark:text-emerald-400',
  pending_match: 'bg-amber-500/15 border-amber-500/30 text-amber-700 dark:text-amber-400',
  completed: 'bg-sky-500/15 border-sky-500/30 text-sky-700 dark:text-sky-400',
  cancelled: 'bg-red-500/15 border-red-500/30 text-red-700 dark:text-red-400',
}

const dayNames = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom']

// ── Component ────────────────────────────────────────────────────────

export function Calendario() {
  const [selectedDate, setSelectedDate] = useState(new Date())
  const [viewMode, setViewMode] = useState<'day' | 'week'>('day')
  const [bookingsByDate, setBookingsByDate] = useState<Record<string, Booking[]>>({})
  const [loading, setLoading] = useState(true)

  const weekStart = useMemo(() => startOfWeek(selectedDate), [selectedDate])
  const weekDays = useMemo(
    () => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)),
    [weekStart]
  )

  const isToday = formatDate(selectedDate) === formatDate(new Date())

  // Fetch bookings for the visible range
  const fetchBookings = useCallback(async () => {
    setLoading(true)
    try {
      const from = viewMode === 'day' ? formatDate(selectedDate) : formatDate(weekDays[0])
      const to = viewMode === 'day' ? formatDate(selectedDate) : formatDate(weekDays[6])

      const data = await apiFetch<Record<string, Booking[]>>(
        `/admin/bookings/calendar?from=${from}&to=${to}`
      )
      setBookingsByDate(data)
    } catch {
      setBookingsByDate({})
    } finally {
      setLoading(false)
    }
  }, [selectedDate, viewMode, weekDays])

  useEffect(() => {
    fetchBookings()
  }, [fetchBookings])

  const navigate = (direction: -1 | 1) => {
    const days = viewMode === 'day' ? 1 : 7
    setSelectedDate(addDays(selectedDate, direction * days))
  }

  const dayBookings = bookingsByDate[formatDate(selectedDate)] ?? []

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Calendario</h1>
          <p className="text-muted-foreground">
            {viewMode === 'day'
              ? selectedDate.toLocaleDateString('it-IT', {
                  weekday: 'long',
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
                })
              : `${weekDays[0].toLocaleDateString('it-IT', { day: 'numeric', month: 'short' })} — ${weekDays[6].toLocaleDateString('it-IT', { day: 'numeric', month: 'short', year: 'numeric' })}`}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => setSelectedDate(new Date())}
            className={isToday && viewMode === 'day' ? 'border-emerald-500 text-emerald-600' : ''}
          >
            Oggi
          </Button>
          <div className="flex items-center rounded-lg border">
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => navigate(-1)}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => navigate(1)}>
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
          <div className="flex rounded-lg border">
            <Button
              variant={viewMode === 'day' ? 'default' : 'ghost'}
              size="sm"
              className="rounded-r-none"
              onClick={() => setViewMode('day')}
            >
              Giorno
            </Button>
            <Button
              variant={viewMode === 'week' ? 'default' : 'ghost'}
              size="sm"
              className="rounded-l-none"
              onClick={() => setViewMode('week')}
            >
              Settimana
            </Button>
          </div>
          <Button size="sm" className="bg-emerald-600 hover:bg-emerald-700">
            <Plus className="mr-1 h-4 w-4" />
            Nuova
          </Button>
        </div>
      </div>

      {/* Calendar grid */}
      <Card className="overflow-hidden">
        {viewMode === 'week' && (
          <div className="grid grid-cols-[60px_repeat(7,1fr)] border-b">
            <div className="border-r" />
            {weekDays.map((day, i) => {
              const isCurrentDay = formatDate(day) === formatDate(new Date())
              const count = (bookingsByDate[formatDate(day)] ?? []).length
              return (
                <button
                  key={i}
                  className={`flex flex-col items-center gap-0.5 py-3 border-r last:border-r-0 transition-colors hover:bg-muted/50 ${
                    isCurrentDay ? 'bg-emerald-50 dark:bg-emerald-950/30' : ''
                  }`}
                  onClick={() => {
                    setSelectedDate(day)
                    setViewMode('day')
                  }}
                >
                  <span className="text-xs text-muted-foreground">{dayNames[i]}</span>
                  <span
                    className={`text-sm font-semibold ${
                      isCurrentDay ? 'text-emerald-600' : ''
                    }`}
                  >
                    {day.getDate()}
                  </span>
                  {count > 0 && (
                    <Badge variant="secondary" className="text-[10px] h-4 px-1">
                      {count}
                    </Badge>
                  )}
                </button>
              )
            })}
          </div>
        )}

        <div className="relative overflow-y-auto" style={{ height: `${HOUR_HEIGHT * 15}px` }}>
          {loading && (
            <div className="absolute inset-0 z-20 flex items-center justify-center bg-background/60">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          )}

          {/* Hour lines */}
          {HOURS.map((hour) => (
            <div
              key={hour}
              className="absolute left-0 right-0 border-b border-dashed border-border/50"
              style={{ top: `${(hour - 8) * HOUR_HEIGHT}px`, height: `${HOUR_HEIGHT}px` }}
            >
              <span className="absolute -top-2.5 left-2 text-xs text-muted-foreground font-mono w-12">
                {String(hour).padStart(2, '0')}:00
              </span>
            </div>
          ))}

          {/* Now indicator */}
          {isToday && viewMode === 'day' && <NowIndicator />}

          {/* Bookings */}
          {viewMode === 'day' ? (
            <DayColumn bookings={dayBookings} offsetLeft={60} />
          ) : (
            <div className="absolute inset-0 left-[60px] grid grid-cols-7">
              {weekDays.map((day, i) => (
                <div key={i} className="relative border-r last:border-r-0">
                  {(bookingsByDate[formatDate(day)] ?? []).map((b) => (
                    <BookingBlock key={b.id} booking={b} />
                  ))}
                </div>
              ))}
            </div>
          )}
        </div>
      </Card>
    </div>
  )
}

// ── Subcomponents ────────────────────────────────────────────────────

function DayColumn({ bookings, offsetLeft }: { bookings: Booking[]; offsetLeft: number }) {
  return (
    <div className="absolute inset-0" style={{ left: `${offsetLeft}px` }}>
      {bookings.map((b) => (
        <BookingBlock key={b.id} booking={b} />
      ))}
    </div>
  )
}

function BookingBlock({ booking }: { booking: Booking }) {
  const startMin = timeToMinutes(booking.start_time) - 8 * 60
  const endMin = timeToMinutes(booking.end_time) - 8 * 60
  const top = (startMin / 60) * HOUR_HEIGHT
  const height = Math.max(((endMin - startMin) / 60) * HOUR_HEIGHT - 2, 30)

  const color = statusColor[booking.status] ?? statusColor.confirmed

  return (
    <div
      className={`absolute inset-x-1 rounded-lg border px-2 py-1 cursor-pointer transition-shadow hover:shadow-md ${color}`}
      style={{ top: `${top}px`, height: `${height}px` }}
    >
      <p className="text-xs font-semibold truncate">
        {booking.player1?.name ?? 'Giocatore'}
        {booking.player2?.name ? ` vs ${booking.player2.name}` : ''}
      </p>
      <p className="text-[11px] opacity-75">
        {booking.start_time} – {booking.end_time} · €{booking.price}
      </p>
    </div>
  )
}

function NowIndicator() {
  const now = new Date()
  const minutes = now.getHours() * 60 + now.getMinutes() - 8 * 60
  if (minutes < 0 || minutes > 14 * 60) return null

  const top = (minutes / 60) * HOUR_HEIGHT

  return (
    <div className="absolute left-0 right-0 z-10 pointer-events-none" style={{ top: `${top}px` }}>
      <div className="flex items-center">
        <div className="h-3 w-3 rounded-full bg-red-500 -ml-1.5" />
        <div className="flex-1 h-0.5 bg-red-500" />
      </div>
    </div>
  )
}
