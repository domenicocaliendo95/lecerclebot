import { useState } from 'react'
import {
  Search,
  ChevronLeft,
  ChevronRight,
  Loader2,
  X,
  MessageCircle,
  User as UserIcon,
  Bot,
  RotateCcw,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useApi, apiFetch } from '@/hooks/use-api'
import type { BotSession, PaginatedResponse } from '@/types/api'

const stateColors: Record<string, string> = {
  NEW: 'bg-blue-100 text-blue-700',
  MENU: 'bg-emerald-100 text-emerald-700',
  CONFERMATO: 'bg-emerald-100 text-emerald-700',
  SCEGLI_QUANDO: 'bg-amber-100 text-amber-700',
  SCEGLI_DURATA: 'bg-amber-100 text-amber-700',
  VERIFICA_SLOT: 'bg-amber-100 text-amber-700',
  PROPONI_SLOT: 'bg-amber-100 text-amber-700',
  CONFERMA: 'bg-amber-100 text-amber-700',
  ATTESA_MATCH: 'bg-purple-100 text-purple-700',
  RISPOSTA_MATCH: 'bg-purple-100 text-purple-700',
}

export function Sessioni() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [selected, setSelected] = useState<BotSession | null>(null)

  const params = new URLSearchParams({ page: String(page), per_page: '20' })
  if (search) params.set('search', search)

  const { data, loading, refetch } = useApi<PaginatedResponse<BotSession>>(`/admin/bot-sessions?${params}`)

  const sessions = data?.data ?? []
  const meta = data?.meta
  const [deleting, setDeleting] = useState<number | null>(null)

  const handleSearch = () => {
    setSearch(searchInput)
    setPage(1)
  }

  const handleReset = async (session: BotSession) => {
    const name = (session.profile as Record<string, string> | null)?.name ?? session.phone
    if (!confirm(`Eliminare la sessione di ${name}?\n\nIl prossimo messaggio ripartirà da zero (onboarding se nuovo, menu se registrato).`)) return
    setDeleting(session.id)
    try {
      await apiFetch(`/admin/bot-sessions/${session.id}`, { method: 'DELETE' })
      if (selected?.id === session.id) setSelected(null)
      refetch()
    } catch { /* */ }
    setDeleting(null)
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Sessioni Bot</h1>
        <p className="text-muted-foreground">
          {meta ? `${meta.total} sessioni` : 'Caricamento...'}
        </p>
      </div>

      {/* Search */}
      <div className="flex items-center gap-3">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="text"
            placeholder="Cerca per telefono o nome..."
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

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Sessions list */}
        <Card className="rounded-xl shadow-sm">
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-slate-600">Sessioni</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {loading ? (
              <div className="loading-center">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                <span className="text-xs text-muted-foreground">Caricamento sessioni...</span>
              </div>
            ) : sessions.length === 0 ? (
              <div className="py-12 text-center text-muted-foreground">
                Nessuna sessione trovata.
              </div>
            ) : (
              <div className="divide-y">
                {sessions.map((s) => {
                  const name = (s.profile as Record<string, string> | null)?.name ?? null
                  const isActive = selected?.id === s.id
                  return (
                    <button
                      key={s.id}
                      className={`w-full flex items-center justify-between px-4 py-3 text-left transition-colors hover:bg-muted/30 ${isActive ? 'bg-muted/50' : ''}`}
                      onClick={() => setSelected(isActive ? null : s)}
                    >
                      <div className="min-w-0">
                        <p className="text-sm font-medium truncate">
                          {name ?? s.phone}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {s.phone} · {s.persona ?? '—'}
                        </p>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${stateColors[s.state] ?? 'bg-gray-100 text-gray-700'}`}>
                          {s.state}
                        </span>
                        <MessageCircle className="h-3.5 w-3.5 text-muted-foreground" />
                        <span className="text-xs text-muted-foreground">{s.history.length}</span>
                      </div>
                    </button>
                  )
                })}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Chat view */}
        <Card className="lg:sticky lg:top-20 lg:self-start rounded-xl shadow-sm">
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-slate-600 flex items-center gap-2">
              <MessageCircle className="h-4 w-4" />
              {selected ? `Chat — ${(selected.profile as Record<string, string> | null)?.name ?? selected.phone}` : 'Seleziona una sessione'}
            </CardTitle>
          </CardHeader>
          <CardContent>
            {!selected ? (
              <div className="py-12 text-center text-muted-foreground text-sm">
                Clicca su una sessione per vedere la conversazione.
              </div>
            ) : selected.history.length === 0 ? (
              <div className="py-12 text-center text-muted-foreground text-sm">
                Nessun messaggio nella cronologia.
              </div>
            ) : (
              <div className="space-y-3 max-h-[600px] overflow-y-auto pr-1">
                {selected.history.map((msg, i) => {
                  const isUser = msg.role === 'user'
                  return (
                    <div
                      key={i}
                      className={`flex gap-2 ${isUser ? '' : 'flex-row-reverse'}`}
                    >
                      <div className={`shrink-0 flex h-7 w-7 items-center justify-center rounded-full ${isUser ? 'bg-blue-100 text-blue-600' : 'bg-emerald-100 text-emerald-600'}`}>
                        {isUser ? <UserIcon className="h-3.5 w-3.5" /> : <Bot className="h-3.5 w-3.5" />}
                      </div>
                      <div
                        className={`rounded-xl px-3 py-2 text-sm max-w-[80%] ${
                          isUser
                            ? 'bg-blue-50 dark:bg-blue-950/30'
                            : 'bg-emerald-50 dark:bg-emerald-950/30'
                        }`}
                      >
                        <p className="whitespace-pre-wrap break-words">{msg.content}</p>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}

            {selected && (
              <div className="mt-4 pt-3 border-t">
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <span className="text-muted-foreground">Stato:</span>{' '}
                    <Badge variant="outline" className="text-[10px]">{selected.state}</Badge>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Persona:</span>{' '}
                    <span className="font-medium">{selected.persona ?? '—'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Aggiornata:</span>{' '}
                    <span>{new Date(selected.updated_at).toLocaleString('it-IT')}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Messaggi:</span>{' '}
                    <span className="font-medium">{selected.history.length}</span>
                  </div>
                </div>

                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => handleReset(selected)}
                  disabled={deleting === selected.id}
                  className="w-full mt-3 text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200"
                >
                  {deleting === selected.id
                    ? <Loader2 className="h-3 w-3 mr-1.5 animate-spin" />
                    : <RotateCcw className="h-3 w-3 mr-1.5" />
                  }
                  Reset sessione
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">Pagina {meta.current_page} di {meta.last_page}</p>
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
