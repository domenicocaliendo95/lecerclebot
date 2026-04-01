import { useState } from 'react'
import {
  Search,
  ChevronLeft,
  ChevronRight,
  Loader2,
  X,
  Trophy,
  ArrowUpDown,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useApi } from '@/hooks/use-api'
import type { User, PaginatedResponse } from '@/types/api'

const levelColors: Record<string, string> = {
  neofita: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-400',
  dilettante: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
  avanzato: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
}

export function Giocatori() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [sort, setSort] = useState('name')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [selected, setSelected] = useState<User | null>(null)

  const params = new URLSearchParams({ page: String(page), per_page: '20', sort, dir: sortDir })
  if (search) params.set('search', search)

  const { data, loading } = useApi<PaginatedResponse<User>>(`/admin/users?${params}`)

  const users = data?.data ?? []
  const meta = data?.meta

  const toggleSort = (field: string) => {
    if (sort === field) {
      setSortDir(sortDir === 'asc' ? 'desc' : 'asc')
    } else {
      setSort(field)
      setSortDir(field === 'name' ? 'asc' : 'desc')
    }
    setPage(1)
  }

  const handleSearch = () => {
    setSearch(searchInput)
    setPage(1)
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Giocatori</h1>
        <p className="text-muted-foreground">
          {meta ? `${meta.total} giocatori registrati` : 'Caricamento...'}
        </p>
      </div>

      {/* Search */}
      <div className="flex items-center gap-3">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="text"
            placeholder="Cerca per nome o telefono..."
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
            className="flex h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 py-1 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        {search && (
          <Button variant="ghost" size="sm" onClick={() => { setSearch(''); setSearchInput(''); setPage(1) }}>
            <X className="mr-1 h-3 w-3" /> Reset
          </Button>
        )}
      </div>

      {/* Table */}
      <Card>
        <CardContent className="p-0">
          {loading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : users.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              Nessun giocatore trovato.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <SortHeader label="Nome" field="name" sort={sort} dir={sortDir} onSort={toggleSort} />
                    <th className="px-4 py-3 text-left font-medium">Telefono</th>
                    <th className="px-4 py-3 text-left font-medium">FIT</th>
                    <th className="px-4 py-3 text-left font-medium">Livello</th>
                    <SortHeader label="ELO" field="elo_rating" sort={sort} dir={sortDir} onSort={toggleSort} />
                    <SortHeader label="Partite" field="matches_played" sort={sort} dir={sortDir} onSort={toggleSort} />
                    <th className="px-4 py-3 text-left font-medium">Età</th>
                    <SortHeader label="Iscritto" field="created_at" sort={sort} dir={sortDir} onSort={toggleSort} />
                  </tr>
                </thead>
                <tbody>
                  {users.map((u) => (
                    <tr
                      key={u.id}
                      className={`border-b cursor-pointer transition-colors hover:bg-muted/30 ${selected?.id === u.id ? 'bg-muted/50' : ''}`}
                      onClick={() => setSelected(selected?.id === u.id ? null : u)}
                    >
                      <td className="px-4 py-3 font-medium">{u.name}</td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{u.phone}</td>
                      <td className="px-4 py-3">
                        {u.is_fit ? (
                          <Badge variant="default" className="text-xs">{u.fit_rating ?? 'FIT'}</Badge>
                        ) : (
                          <span className="text-muted-foreground text-xs">No</span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        {u.self_level && (
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${levelColors[u.self_level] ?? ''}`}>
                            {u.self_level}
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-1">
                          <Trophy className="h-3 w-3 text-amber-500" />
                          <span className="font-medium">{u.elo_rating}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span>{u.matches_played}</span>
                        {u.matches_won > 0 && (
                          <span className="text-muted-foreground text-xs ml-1">
                            ({u.matches_won}W)
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3">{u.age ?? '—'}</td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">
                        {timeAgo(u.created_at)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Detail */}
      {selected && <PlayerDetail user={selected} onClose={() => setSelected(null)} />}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Pagina {meta.current_page} di {meta.last_page}
          </p>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => setPage(page - 1)}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => setPage(page + 1)}>
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function SortHeader({ label, field, sort, dir, onSort }: {
  label: string; field: string; sort: string; dir: string; onSort: (f: string) => void
}) {
  return (
    <th className="px-4 py-3 text-left font-medium">
      <button className="flex items-center gap-1 hover:text-foreground" onClick={() => onSort(field)}>
        {label}
        <ArrowUpDown className={`h-3 w-3 ${sort === field ? 'text-foreground' : 'text-muted-foreground/50'}`} />
        {sort === field && <span className="text-[10px]">{dir === 'asc' ? '↑' : '↓'}</span>}
      </button>
    </th>
  )
}

function PlayerDetail({ user: u, onClose }: { user: User; onClose: () => void }) {
  const winRate = u.matches_played > 0
    ? Math.round((u.matches_won / u.matches_played) * 100)
    : 0

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="text-base">{u.name}</CardTitle>
        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onClose}>
          <X className="h-4 w-4" />
        </Button>
      </CardHeader>
      <CardContent>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p className="text-xs text-muted-foreground">Telefono</p>
            <p className="font-medium font-mono">{u.phone}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Età</p>
            <p className="font-medium">{u.age ?? '—'}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Tesserato FIT</p>
            <p className="font-medium">{u.is_fit ? `Sì — ${u.fit_rating ?? 'N/C'}` : 'No'}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Livello</p>
            <p className="font-medium capitalize">{u.self_level ?? '—'}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">ELO</p>
            <div className="flex items-center gap-1.5">
              <Trophy className="h-4 w-4 text-amber-500" />
              <span className="text-lg font-bold">{u.elo_rating}</span>
              {u.is_elo_established && <Badge variant="outline" className="text-[10px]">Stabilizzato</Badge>}
            </div>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Partite</p>
            <p className="font-medium">{u.matches_played} giocate · {u.matches_won} vinte ({winRate}%)</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Fasce orarie preferite</p>
            <p className="font-medium capitalize">{u.preferred_slots?.join(', ') ?? '—'}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Iscritto</p>
            <p className="font-medium">{new Date(u.created_at).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })}</p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const days = Math.floor(diff / 86400000)
  if (days === 0) return 'oggi'
  if (days === 1) return 'ieri'
  if (days < 7) return `${days}g fa`
  if (days < 30) return `${Math.floor(days / 7)}sett fa`
  return `${Math.floor(days / 30)}m fa`
}
