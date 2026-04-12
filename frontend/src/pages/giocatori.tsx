import { useState, useCallback } from 'react'
import {
  Search, ChevronLeft, ChevronRight, Loader2, X, Trophy, ArrowUpDown, Pencil, Trash2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { FormDialog, FormField, inputClass, selectClass } from '@/components/ui/form-dialog'
import { useApi, apiFetch } from '@/hooks/use-api'
import type { User, PaginatedResponse } from '@/types/api'

const levelColors: Record<string, string> = {
  neofita: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-400 dark:border-blue-800',
  dilettante: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-400 dark:border-amber-800',
  avanzato: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-400 dark:border-emerald-800',
}

interface UserForm { name: string; phone: string; is_fit: boolean; fit_rating: string; self_level: string; age: string; elo_rating: string }
const emptyForm: UserForm = { name: '', phone: '', is_fit: false, fit_rating: '', self_level: '', age: '', elo_rating: '1200' }

export function Giocatori() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [sort, setSort] = useState('name')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [selected, setSelected] = useState<User | null>(null)
  const [editing, setEditing] = useState<User | null>(null)
  const [deleting, setDeleting] = useState<User | null>(null)
  const [form, setForm] = useState<UserForm>(emptyForm)
  const [submitting, setSubmitting] = useState(false)

  const params = new URLSearchParams({ page: String(page), per_page: '20', sort, dir: sortDir })
  if (search) params.set('search', search)

  const { data, loading, refetch } = useApi<PaginatedResponse<User>>(`/admin/users?${params}`)
  const users = data?.data ?? []
  const meta = data?.meta

  const set = useCallback((k: keyof UserForm, v: string | boolean) => setForm(f => ({ ...f, [k]: v })), [])

  const toggleSort = (field: string) => {
    if (sort === field) setSortDir(sortDir === 'asc' ? 'desc' : 'asc')
    else { setSort(field); setSortDir(field === 'name' ? 'asc' : 'desc') }
    setPage(1)
  }

  const openEdit = (u: User) => {
    setForm({
      name: u.name, phone: u.phone, is_fit: u.is_fit,
      fit_rating: u.fit_rating ?? '', self_level: u.self_level ?? '',
      age: u.age ? String(u.age) : '', elo_rating: String(u.elo_rating),
    })
    setEditing(u)
  }

  const handleSave = async () => {
    if (!editing) return
    setSubmitting(true)
    try {
      await apiFetch(`/admin/users/${editing.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          ...form, age: form.age ? Number(form.age) : null,
          elo_rating: Number(form.elo_rating),
          fit_rating: form.fit_rating || null, self_level: form.self_level || null,
        }),
      })
      setEditing(null); refetch()
    } catch { /* */ }
    setSubmitting(false)
  }

  const handleDelete = async () => {
    if (!deleting) return
    setSubmitting(true)
    try {
      await apiFetch(`/admin/users/${deleting.id}`, { method: 'DELETE' })
      setDeleting(null); setSelected(null); refetch()
    } catch { /* */ }
    setSubmitting(false)
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Giocatori</h1>
        <p className="text-muted-foreground">{meta ? `${meta.total} registrati` : 'Caricamento...'}</p>
      </div>

      <div className="flex items-center gap-3">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input type="text" placeholder="Cerca per nome o telefono..." value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && (setSearch(searchInput), setPage(1))}
            className={`${inputClass} pl-9`} />
        </div>
        {search && <Button variant="ghost" size="sm" onClick={() => { setSearch(''); setSearchInput(''); setPage(1) }}><X className="mr-1 h-3 w-3" /> Reset</Button>}
      </div>

      <Card className="overflow-hidden rounded-xl shadow-sm">
        <CardContent className="p-0">
          {loading ? (
            <div className="loading-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /><span className="text-xs text-muted-foreground">Caricamento giocatori...</span></div>
          ) : users.length === 0 ? (
            <div className="py-16 text-center text-muted-foreground">Nessun giocatore trovato.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b bg-muted/40">
                  <SortHeader label="Nome" field="name" sort={sort} onSort={toggleSort} />
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Telefono</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">FIT</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Livello</th>
                  <SortHeader label="ELO" field="elo_rating" sort={sort} onSort={toggleSort} />
                  <SortHeader label="Partite" field="matches_played" sort={sort} onSort={toggleSort} />
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Età</th>
                  <SortHeader label="Iscritto" field="created_at" sort={sort} onSort={toggleSort} />
                  <th className="px-4 py-3 w-20" />
                </tr></thead>
                <tbody>
                  {users.map((u) => (
                    <tr key={u.id} className="border-b transition-colors hover:bg-muted/20 group cursor-pointer"
                      onClick={() => setSelected(selected?.id === u.id ? null : u)}>
                      <td className="px-4 py-3 font-medium">{u.name}</td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{u.phone}</td>
                      <td className="px-4 py-3">
                        {u.is_fit ? <Badge variant="default" className="text-xs">{u.fit_rating ?? 'FIT'}</Badge>
                          : <span className="text-muted-foreground text-xs">No</span>}
                      </td>
                      <td className="px-4 py-3">
                        {u.self_level && <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${levelColors[u.self_level] ?? ''}`}>{u.self_level}</span>}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-1"><Trophy className="h-3 w-3 text-amber-500" /><span className="font-semibold">{u.elo_rating}</span></div>
                      </td>
                      <td className="px-4 py-3">
                        {u.matches_played}<span className="text-muted-foreground text-xs ml-1">({u.matches_won}W)</span>
                      </td>
                      <td className="px-4 py-3">{u.age ?? '—'}</td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{timeAgo(u.created_at)}</td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                          <button onClick={(e) => { e.stopPropagation(); openEdit(u) }} className="rounded p-1 hover:bg-muted"><Pencil className="h-3.5 w-3.5" /></button>
                          <button onClick={(e) => { e.stopPropagation(); setDeleting(u) }} className="rounded p-1 hover:bg-red-100 text-red-500"><Trash2 className="h-3.5 w-3.5" /></button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {selected && <PlayerDetail user={selected} onClose={() => setSelected(null)} />}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">Pagina {meta.current_page} di {meta.last_page}</p>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => setPage(page - 1)}><ChevronLeft className="h-4 w-4" /></Button>
            <Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => setPage(page + 1)}><ChevronRight className="h-4 w-4" /></Button>
          </div>
        </div>
      )}

      {/* Edit Dialog */}
      <FormDialog open={!!editing} onClose={() => setEditing(null)} title={`Modifica ${editing?.name ?? ''}`} onSubmit={handleSave} submitting={submitting}>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Nome"><input value={form.name} onChange={e => set('name', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Telefono"><input value={form.phone} onChange={e => set('phone', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Tesserato FIT">
            <select value={form.is_fit ? 'true' : 'false'} onChange={e => set('is_fit', e.target.value === 'true')} className={selectClass}>
              <option value="false">No</option><option value="true">Sì</option>
            </select>
          </FormField>
          <FormField label="Classifica FIT"><input value={form.fit_rating} onChange={e => set('fit_rating', e.target.value)} className={inputClass} placeholder="es. 4.1, NC" /></FormField>
          <FormField label="Livello">
            <select value={form.self_level} onChange={e => set('self_level', e.target.value)} className={selectClass}>
              <option value="">—</option><option value="neofita">Neofita</option><option value="dilettante">Dilettante</option><option value="avanzato">Avanzato</option>
            </select>
          </FormField>
          <FormField label="Età"><input type="number" value={form.age} onChange={e => set('age', e.target.value)} className={inputClass} /></FormField>
          <FormField label="ELO"><input type="number" value={form.elo_rating} onChange={e => set('elo_rating', e.target.value)} className={inputClass} /></FormField>
        </div>
      </FormDialog>

      {/* Delete Dialog */}
      <FormDialog open={!!deleting} onClose={() => setDeleting(null)} title="Elimina giocatore"
        onSubmit={handleDelete} submitting={submitting} submitLabel="Elimina" destructive>
        <p className="text-sm">Eliminare <strong>{deleting?.name}</strong>? L'operazione non è reversibile.</p>
      </FormDialog>
    </div>
  )
}

function SortHeader({ label, field, sort, onSort }: { label: string; field: string; sort: string; onSort: (f: string) => void }) {
  return (
    <th className="px-4 py-3 text-left font-medium text-muted-foreground">
      <button className="flex items-center gap-1 hover:text-foreground transition-colors" onClick={() => onSort(field)}>
        {label}
        <ArrowUpDown className={`h-3 w-3 ${sort === field ? 'text-foreground' : 'text-muted-foreground/40'}`} />
      </button>
    </th>
  )
}

function PlayerDetail({ user: u, onClose }: { user: User; onClose: () => void }) {
  const winRate = u.matches_played > 0 ? Math.round((u.matches_won / u.matches_played) * 100) : 0
  return (
    <Card className="border-l-4 border-l-amber-500">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="text-base">{u.name}</CardTitle>
        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onClose}><X className="h-4 w-4" /></Button>
      </CardHeader>
      <CardContent>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <InfoItem label="Telefono" value={u.phone} mono />
          <InfoItem label="Età" value={u.age ? String(u.age) : '—'} />
          <InfoItem label="FIT" value={u.is_fit ? `Sì — ${u.fit_rating ?? 'N/C'}` : 'No'} />
          <InfoItem label="Livello" value={u.self_level ?? '—'} capitalize />
          <div>
            <p className="text-xs text-muted-foreground mb-0.5">ELO</p>
            <div className="flex items-center gap-1.5">
              <Trophy className="h-4 w-4 text-amber-500" />
              <span className="text-xl font-bold">{u.elo_rating}</span>
              {u.is_elo_established && <Badge variant="outline" className="text-[10px]">Stabile</Badge>}
            </div>
          </div>
          <InfoItem label="Partite" value={`${u.matches_played} giocate · ${u.matches_won} vinte (${winRate}%)`} />
          <InfoItem label="Fasce orarie" value={u.preferred_slots?.join(', ') ?? '—'} capitalize />
          <InfoItem label="Iscritto" value={new Date(u.created_at).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })} />
        </div>
      </CardContent>
    </Card>
  )
}

function InfoItem({ label, value, mono, capitalize: cap, large }: { label: string; value: string; mono?: boolean; capitalize?: boolean; large?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground mb-0.5">{label}</p>
      <p className={`${large ? 'text-lg font-bold' : 'font-medium'} ${mono ? 'font-mono text-sm' : ''} ${cap ? 'capitalize' : ''}`}>{value}</p>
    </div>
  )
}

function timeAgo(dateStr: string): string {
  const days = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86400000)
  if (days === 0) return 'oggi'
  if (days === 1) return 'ieri'
  if (days < 7) return `${days}g fa`
  if (days < 30) return `${Math.floor(days / 7)}sett fa`
  return `${Math.floor(days / 30)}m fa`
}
