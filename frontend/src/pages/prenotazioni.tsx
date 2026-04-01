import { useState } from 'react'
import {
  Search,
  Filter,
  ChevronLeft,
  ChevronRight,
  Loader2,
  X,
  Clock,
  Zap,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useApi } from '@/hooks/use-api'
import type { Booking, PaginatedResponse } from '@/types/api'

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive'; color: string }> = {
  confirmed:     { label: 'Confermata',  variant: 'default',     color: 'bg-emerald-500' },
  pending_match: { label: 'In attesa',   variant: 'secondary',   color: 'bg-amber-500' },
  completed:     { label: 'Completata',  variant: 'outline',     color: 'bg-sky-500' },
  cancelled:     { label: 'Annullata',   variant: 'destructive', color: 'bg-red-500' },
}

export function Prenotazioni() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [statusFilter, setStatusFilter] = useState<string | null>(null)
  const [selected, setSelected] = useState<Booking | null>(null)

  const params = new URLSearchParams({ page: String(page), per_page: '15' })
  if (search) params.set('player', search)
  if (statusFilter) params.set('status', statusFilter)

  const { data, loading } = useApi<PaginatedResponse<Booking>>(`/admin/bookings?${params}`)

  const bookings = data?.data ?? []
  const meta = data?.meta

  const handleSearch = () => {
    setSearch(searchInput)
    setPage(1)
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Prenotazioni</h1>
        <p className="text-muted-foreground">
          {meta ? `${meta.total} prenotazioni totali` : 'Caricamento...'}
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="text"
            placeholder="Cerca giocatore..."
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
            className="flex h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 py-1 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <div className="flex items-center gap-2">
          <Filter className="h-4 w-4 text-muted-foreground" />
          {Object.entries(statusConfig).map(([key, cfg]) => (
            <Button
              key={key}
              variant={statusFilter === key ? 'default' : 'outline'}
              size="sm"
              onClick={() => {
                setStatusFilter(statusFilter === key ? null : key)
                setPage(1)
              }}
            >
              {cfg.label}
            </Button>
          ))}
          {(search || statusFilter) && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSearch('')
                setSearchInput('')
                setStatusFilter(null)
                setPage(1)
              }}
            >
              <X className="mr-1 h-3 w-3" />
              Reset
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <Card>
        <CardContent className="p-0">
          {loading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : bookings.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              Nessuna prenotazione trovata.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="px-4 py-3 text-left font-medium">Data</th>
                    <th className="px-4 py-3 text-left font-medium">Orario</th>
                    <th className="px-4 py-3 text-left font-medium">Giocatori</th>
                    <th className="px-4 py-3 text-left font-medium">Stato</th>
                    <th className="px-4 py-3 text-right font-medium">Prezzo</th>
                  </tr>
                </thead>
                <tbody>
                  {bookings.map((b) => {
                    const st = statusConfig[b.status] ?? statusConfig.confirmed
                    const isSelected = selected?.id === b.id
                    return (
                      <tr
                        key={b.id}
                        className={`border-b cursor-pointer transition-colors hover:bg-muted/30 ${isSelected ? 'bg-muted/50' : ''}`}
                        onClick={() => setSelected(isSelected ? null : b)}
                      >
                        <td className="px-4 py-3">
                          <span className="font-medium">
                            {new Date(b.booking_date).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' })}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1.5">
                            <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                            {b.start_time} – {b.end_time}
                            {b.is_peak && <Zap className="h-3 w-3 text-amber-500" />}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <span>{b.player1?.name ?? '—'}</span>
                          {b.player2?.name && (
                            <span className="text-muted-foreground"> vs {b.player2.name}</span>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant={st.variant}>{st.label}</Badge>
                        </td>
                        <td className="px-4 py-3 text-right font-medium">€{b.price}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Detail panel */}
      {selected && <BookingDetail booking={selected} onClose={() => setSelected(null)} />}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Pagina {meta.current_page} di {meta.last_page}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() => setPage(page - 1)}
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setPage(page + 1)}
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function BookingDetail({ booking: b, onClose }: { booking: Booking; onClose: () => void }) {
  const st = statusConfig[b.status] ?? statusConfig.confirmed
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="text-base">Dettaglio prenotazione #{b.id}</CardTitle>
        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onClose}>
          <X className="h-4 w-4" />
        </Button>
      </CardHeader>
      <CardContent>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p className="text-xs text-muted-foreground">Data</p>
            <p className="font-medium">
              {new Date(b.booking_date).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
            </p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Orario</p>
            <p className="font-medium">{b.start_time} – {b.end_time} {b.is_peak && '(Peak)'}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Giocatore 1</p>
            <p className="font-medium">{b.player1?.name ?? '—'}</p>
            <p className="text-xs text-muted-foreground">{b.player1?.phone}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Giocatore 2</p>
            <p className="font-medium">{b.player2?.name ?? '—'}</p>
            <p className="text-xs text-muted-foreground">{b.player2?.phone}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Stato</p>
            <Badge variant={st.variant} className="mt-1">{st.label}</Badge>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Prezzo</p>
            <p className="font-medium text-lg">€{b.price}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Pagamento P1</p>
            <p className="font-medium">{b.payment_status_p1}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Pagamento P2</p>
            <p className="font-medium">{b.payment_status_p2 ?? '—'}</p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
