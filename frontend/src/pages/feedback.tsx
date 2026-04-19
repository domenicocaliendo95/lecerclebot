import { useState } from 'react'
import { Star, MessageCircle, Check, CheckCheck, Filter, User } from 'lucide-react'
import { useApi, apiFetch } from '@/hooks/use-api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

type FeedbackItem = {
  id: number
  rating: number
  content: { text?: string } | null
  type: string
  is_read: boolean
  created_at: string
  user: { id: number; name: string; phone: string } | null
  booking: { id: number; date: string; time: string } | null
}

type FeedbackResponse = {
  data: FeedbackItem[]
  meta: { current_page: number; last_page: number; total: number }
  stats: { total: number; unread: number; avg: number; by_rating: Record<string, number> }
}

const RATING_LABELS: Record<number, { label: string; color: string; emoji: string }> = {
  1: { label: 'Scarso', color: 'text-red-600 bg-red-50 border-red-200', emoji: '😞' },
  2: { label: 'Mediocre', color: 'text-orange-600 bg-orange-50 border-orange-200', emoji: '😐' },
  3: { label: 'Medio', color: 'text-amber-600 bg-amber-50 border-amber-200', emoji: '🙂' },
  4: { label: 'Buono', color: 'text-lime-600 bg-lime-50 border-lime-200', emoji: '😊' },
  5: { label: 'Ottimo', color: 'text-emerald-600 bg-emerald-50 border-emerald-200', emoji: '🤩' },
}

const ratingInfo = (r: number) => RATING_LABELS[r] ?? RATING_LABELS[3]

export function Feedback() {
  const [filter, setFilter] = useState<string>('')
  const [page, setPage] = useState(1)
  const url = `/admin/feedbacks?per_page=20&page=${page}${filter ? `&${filter}` : ''}`
  const { data, loading, refetch } = useApi<FeedbackResponse>(url)

  const markRead = async (id: number) => {
    await apiFetch(`/admin/feedbacks/${id}/read`, { method: 'POST' })
    refetch()
  }

  const markAllRead = async () => {
    await apiFetch('/admin/feedbacks/read-all', { method: 'POST' })
    refetch()
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Feedback</h1>
          <p className="text-muted-foreground">
            Valutazioni e commenti ricevuti dai giocatori dopo le partite
          </p>
        </div>
        {data && data.stats.unread > 0 && (
          <Button variant="outline" size="sm" onClick={markAllRead}>
            <CheckCheck className="mr-1.5 h-4 w-4" />
            Segna tutti letti ({data.stats.unread})
          </Button>
        )}
      </div>

      {/* Stats */}
      {data && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <Card>
            <CardContent className="pt-4 pb-3 px-4">
              <div className="text-2xl font-bold">{data.stats.total}</div>
              <div className="text-xs text-muted-foreground">Totali</div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4 pb-3 px-4">
              <div className="text-2xl font-bold flex items-center gap-1">
                {data.stats.avg || '-'}
                <Star className="h-5 w-5 text-amber-500 fill-amber-500" />
              </div>
              <div className="text-xs text-muted-foreground">Media</div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4 pb-3 px-4">
              <div className="text-2xl font-bold text-amber-600">{data.stats.unread}</div>
              <div className="text-xs text-muted-foreground">Da leggere</div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4 pb-3 px-4">
              <div className="flex gap-1">
                {[1, 2, 3, 4, 5].map(r => (
                  <div key={r} className="flex-1 text-center">
                    <div className="text-sm font-semibold">{data.stats.by_rating[r] ?? 0}</div>
                    <div className="text-[10px] text-muted-foreground">{r}★</div>
                  </div>
                ))}
              </div>
              <div className="text-xs text-muted-foreground mt-1">Distribuzione</div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Filtri */}
      <div className="flex gap-2 flex-wrap items-center">
        <Filter className="h-4 w-4 text-muted-foreground" />
        <Button size="sm" variant={filter === '' ? 'default' : 'outline'} onClick={() => { setFilter(''); setPage(1) }}>
          Tutti
        </Button>
        <Button size="sm" variant={filter === 'unread_only=1' ? 'default' : 'outline'} onClick={() => { setFilter('unread_only=1'); setPage(1) }}>
          Da leggere
        </Button>
        {[5, 3, 1].map(r => (
          <Button key={r} size="sm" variant={filter === `rating=${r}` ? 'default' : 'outline'}
            onClick={() => { setFilter(`rating=${r}`); setPage(1) }}>
            {ratingInfo(r).emoji} {ratingInfo(r).label}
          </Button>
        ))}
      </div>

      {/* Lista */}
      {loading ? (
        <div className="text-center py-8 text-muted-foreground">Caricamento...</div>
      ) : !data || data.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center">
            <MessageCircle className="h-10 w-10 text-muted-foreground/30 mx-auto mb-3" />
            <p className="text-muted-foreground">Nessun feedback trovato.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {data.data.map(f => {
            const ri = ratingInfo(f.rating)
            const comment = f.content?.text ?? null
            const date = f.created_at ? new Date(f.created_at).toLocaleDateString('it-IT', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''

            return (
              <Card key={f.id} className={`transition-all ${!f.is_read ? 'border-l-4 border-l-amber-400' : ''}`}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-3 min-w-0">
                      {/* Rating badge */}
                      <div className={`shrink-0 flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-sm font-semibold ${ri.color}`}>
                        <span>{ri.emoji}</span>
                        <span>{f.rating}/5</span>
                      </div>

                      <div className="min-w-0">
                        {/* Utente + data */}
                        <div className="flex items-center gap-2 flex-wrap">
                          {f.user ? (
                            <span className="font-medium text-sm flex items-center gap-1">
                              <User className="h-3.5 w-3.5 text-muted-foreground" />
                              {f.user.name}
                            </span>
                          ) : (
                            <span className="text-sm text-muted-foreground">Utente anonimo</span>
                          )}
                          <span className="text-xs text-muted-foreground">{date}</span>
                          {f.booking && (
                            <Badge variant="outline" className="text-[10px]">
                              Partita {f.booking.date} {f.booking.time}
                            </Badge>
                          )}
                          <Badge variant="secondary" className="text-[10px]">{f.type}</Badge>
                        </div>

                        {/* Commento */}
                        {comment ? (
                          <p className="mt-1.5 text-sm text-foreground leading-relaxed">
                            &ldquo;{comment}&rdquo;
                          </p>
                        ) : (
                          <p className="mt-1 text-xs text-muted-foreground italic">Nessun commento</p>
                        )}
                      </div>
                    </div>

                    {/* Mark read */}
                    {!f.is_read && (
                      <Button variant="ghost" size="sm" className="shrink-0" onClick={() => markRead(f.id)} title="Segna come letto">
                        <Check className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      {/* Paginazione */}
      {data && data.meta.last_page > 1 && (
        <div className="flex justify-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
            Precedente
          </Button>
          <span className="text-sm text-muted-foreground self-center">
            {data.meta.current_page} / {data.meta.last_page}
          </span>
          <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(p => p + 1)}>
            Successiva
          </Button>
        </div>
      )}
    </div>
  )
}
