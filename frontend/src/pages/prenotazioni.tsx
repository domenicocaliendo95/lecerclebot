import { useState, useCallback } from 'react'
import {
  Search, Filter, ChevronLeft, ChevronRight, Loader2, X, Clock, Zap, Plus, Pencil, Trash2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { FormDialog, FormField, inputClass, selectClass } from '@/components/ui/form-dialog'
import { PlayerSearch } from '@/components/ui/player-search'
import { useApi, apiFetch } from '@/hooks/use-api'
import type { Booking, PaginatedResponse } from '@/types/api'

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive' }> = {
  confirmed:     { label: 'Confermata',  variant: 'default' },
  pending_match: { label: 'In attesa',   variant: 'secondary' },
  completed:     { label: 'Completata',  variant: 'outline' },
  cancelled:     { label: 'Annullata',   variant: 'destructive' },
}

interface BookingForm {
  player1_id: string
  player2_id: string
  booking_date: string
  start_time: string
  end_time: string
  status: string
}

const emptyForm: BookingForm = { player1_id: '', player2_id: '', booking_date: '', start_time: '', end_time: '', status: 'confirmed' }

export function Prenotazioni() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [statusFilter, setStatusFilter] = useState<string | null>(null)
  const [selected, setSelected] = useState<Booking | null>(null)

  // CRUD state
  const [showCreate, setShowCreate] = useState(false)
  const [editing, setEditing] = useState<Booking | null>(null)
  const [deleting, setDeleting] = useState<Booking | null>(null)
  const [form, setForm] = useState<BookingForm>(emptyForm)
  const [submitting, setSubmitting] = useState(false)

  const params = new URLSearchParams({ page: String(page), per_page: '15' })
  if (search) params.set('player', search)
  if (statusFilter) params.set('status', statusFilter)

  const { data, loading, refetch } = useApi<PaginatedResponse<Booking>>(`/admin/bookings?${params}`)
  const bookings = data?.data ?? []
  const meta = data?.meta

  const set = useCallback((k: keyof BookingForm, v: string) => setForm(f => ({ ...f, [k]: v })), [])

  const openCreate = () => { setForm(emptyForm); setShowCreate(true) }
  const openEdit = (b: Booking) => {
    setForm({
      player1_id: String(b.player1_id),
      player2_id: b.player2_id ? String(b.player2_id) : '',
      booking_date: b.booking_date,
      start_time: b.start_time,
      end_time: b.end_time,
      status: b.status,
    })
    setEditing(b)
  }

  const handleSave = async () => {
    setSubmitting(true)
    try {
      const body = { ...form, player2_id: form.player2_id || null }
      if (editing) {
        await apiFetch(`/admin/bookings/${editing.id}`, { method: 'PUT', body: JSON.stringify(body) })
      } else {
        await apiFetch('/admin/bookings', { method: 'POST', body: JSON.stringify(body) })
      }
      setShowCreate(false); setEditing(null); refetch()
    } catch { /* handled by apiFetch */ }
    setSubmitting(false)
  }

  const handleDelete = async () => {
    if (!deleting) return
    setSubmitting(true)
    try {
      await apiFetch(`/admin/bookings/${deleting.id}`, { method: 'DELETE' })
      setDeleting(null); setSelected(null); refetch()
    } catch { /* */ }
    setSubmitting(false)
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Prenotazioni</h1>
          <p className="text-muted-foreground">{meta ? `${meta.total} totali` : 'Caricamento...'}</p>
        </div>
        <Button onClick={openCreate} className="bg-emerald-600 hover:bg-emerald-700">
          <Plus className="mr-1.5 h-4 w-4" /> Nuova prenotazione
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input type="text" placeholder="Cerca giocatore..." value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && (setSearch(searchInput), setPage(1))}
            className={`${inputClass} pl-9`} />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Filter className="h-4 w-4 text-muted-foreground" />
          {Object.entries(statusConfig).map(([key, cfg]) => (
            <Button key={key} variant={statusFilter === key ? 'default' : 'outline'} size="sm"
              onClick={() => { setStatusFilter(statusFilter === key ? null : key); setPage(1) }}>
              {cfg.label}
            </Button>
          ))}
          {(search || statusFilter) && (
            <Button variant="ghost" size="sm" onClick={() => { setSearch(''); setSearchInput(''); setStatusFilter(null); setPage(1) }}>
              <X className="mr-1 h-3 w-3" /> Reset
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <Card className="overflow-hidden rounded-xl shadow-sm">
        <CardContent className="p-0">
          {loading ? (
            <div className="loading-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /><span className="text-xs text-muted-foreground">Caricamento prenotazioni...</span></div>
          ) : bookings.length === 0 ? (
            <div className="py-16 text-center text-muted-foreground">Nessuna prenotazione trovata.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b bg-muted/40">
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Data</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Orario</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Giocatori</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Stato</th>
                  <th className="px-4 py-3 text-right font-medium text-muted-foreground">Prezzo</th>
                  <th className="px-4 py-3 w-20" />
                </tr></thead>
                <tbody>
                  {bookings.map((b) => {
                    const st = statusConfig[b.status] ?? statusConfig.confirmed
                    return (
                      <tr key={b.id} className="border-b transition-colors hover:bg-muted/20 group"
                        onClick={() => setSelected(selected?.id === b.id ? null : b)}>
                        <td className="px-4 py-3 font-medium">
                          {new Date(b.booking_date).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' })}
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1.5">
                            <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                            <span>{b.start_time} – {b.end_time}</span>
                            {b.is_peak && <Zap className="h-3 w-3 text-amber-500" />}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          {b.player1?.name ?? '—'}
                          {b.player2?.name && <span className="text-muted-foreground"> vs {b.player2.name}</span>}
                        </td>
                        <td className="px-4 py-3"><Badge variant={st.variant}>{st.label}</Badge></td>
                        <td className="px-4 py-3 text-right font-semibold">€{b.price}</td>
                        <td className="px-4 py-3">
                          <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button onClick={(e) => { e.stopPropagation(); openEdit(b) }}
                              className="rounded p-1 hover:bg-muted"><Pencil className="h-3.5 w-3.5" /></button>
                            <button onClick={(e) => { e.stopPropagation(); setDeleting(b) }}
                              className="rounded p-1 hover:bg-red-100 text-red-500"><Trash2 className="h-3.5 w-3.5" /></button>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Detail */}
      {selected && <BookingDetail booking={selected} onClose={() => setSelected(null)} />}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">Pagina {meta.current_page} di {meta.last_page}</p>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => setPage(page - 1)}><ChevronLeft className="h-4 w-4" /></Button>
            <Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => setPage(page + 1)}><ChevronRight className="h-4 w-4" /></Button>
          </div>
        </div>
      )}

      {/* Create / Edit Dialog */}
      <FormDialog open={showCreate || !!editing} onClose={() => { setShowCreate(false); setEditing(null) }}
        title={editing ? 'Modifica prenotazione' : 'Nuova prenotazione'} onSubmit={handleSave} submitting={submitting}>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Giocatore 1">
            <PlayerSearch value={form.player1_id} onChange={(id) => set('player1_id', id)} placeholder="Cerca giocatore..." />
          </FormField>
          <FormField label="Giocatore 2" hint="Opzionale">
            <PlayerSearch value={form.player2_id} onChange={(id) => set('player2_id', id)} placeholder="Cerca avversario..." />
          </FormField>
          <FormField label="Data">
            <input type="date" value={form.booking_date} onChange={e => set('booking_date', e.target.value)} className={inputClass} />
          </FormField>
          <FormField label="Stato">
            <select value={form.status} onChange={e => set('status', e.target.value)} className={selectClass}>
              <option value="confirmed">Confermata</option>
              <option value="pending_match">In attesa</option>
              <option value="completed">Completata</option>
              <option value="cancelled">Annullata</option>
            </select>
          </FormField>
          <FormField label="Ora inizio">
            <input type="time" value={form.start_time} onChange={e => set('start_time', e.target.value)} className={inputClass} />
          </FormField>
          <FormField label="Ora fine">
            <input type="time" value={form.end_time} onChange={e => set('end_time', e.target.value)} className={inputClass} />
          </FormField>
        </div>
      </FormDialog>

      {/* Delete confirm */}
      <FormDialog open={!!deleting} onClose={() => setDeleting(null)} title="Annulla prenotazione"
        onSubmit={handleDelete} submitting={submitting} submitLabel="Annulla prenotazione" destructive>
        <p className="text-sm">Sei sicuro di voler annullare la prenotazione di <strong>{deleting?.player1?.name}</strong> del {deleting?.booking_date ? new Date(deleting.booking_date).toLocaleDateString('it-IT') : ''}?</p>
      </FormDialog>
    </div>
  )
}

function BookingDetail({ booking: b, onClose }: { booking: Booking; onClose: () => void }) {
  const st = statusConfig[b.status] ?? statusConfig.confirmed
  return (
    <Card className="border-l-4 border-l-emerald-500">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="text-base">Prenotazione #{b.id}</CardTitle>
        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onClose}><X className="h-4 w-4" /></Button>
      </CardHeader>
      <CardContent>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <InfoItem label="Data" value={new Date(b.booking_date).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })} />
          <InfoItem label="Orario" value={`${b.start_time} – ${b.end_time} ${b.is_peak ? '(Peak)' : ''}`} />
          <InfoItem label="Giocatore 1" value={b.player1?.name ?? '—'} sub={b.player1?.phone} />
          <InfoItem label="Giocatore 2" value={b.player2?.name ?? '—'} sub={b.player2?.phone} />
          <div><p className="text-xs text-muted-foreground mb-1">Stato</p><Badge variant={st.variant}>{st.label}</Badge></div>
          <InfoItem label="Prezzo" value={`€${b.price}`} large />
          <InfoItem label="Pagamento P1" value={b.payment_status_p1} />
          <InfoItem label="Pagamento P2" value={b.payment_status_p2 ?? '—'} />
        </div>
      </CardContent>
    </Card>
  )
}

function InfoItem({ label, value, sub, large }: { label: string; value: string; sub?: string; large?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground mb-0.5">{label}</p>
      <p className={large ? 'text-lg font-bold' : 'font-medium'}>{value}</p>
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
    </div>
  )
}
