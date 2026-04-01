import { useState } from 'react'
import {
  Trophy,
  ChevronLeft,
  ChevronRight,
  Loader2,
  TrendingUp,
  TrendingDown,
  Minus,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useApi } from '@/hooks/use-api'
import type { MatchResult, User, PaginatedResponse } from '@/types/api'

export function Match() {
  const [tab, setTab] = useState<'results' | 'ranking'>('results')

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Match</h1>
        <p className="text-muted-foreground">Risultati partite e classifica ELO.</p>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as 'results' | 'ranking')}>
        <TabsList>
          <TabsTrigger value="results">Risultati</TabsTrigger>
          <TabsTrigger value="ranking">Classifica ELO</TabsTrigger>
        </TabsList>
      </Tabs>

      {tab === 'results' ? <MatchResults /> : <EloRanking />}
    </div>
  )
}

function MatchResults() {
  const [page, setPage] = useState(1)
  const { data, loading } = useApi<PaginatedResponse<MatchResult>>(`/admin/match-results?page=${page}&per_page=15`)

  const results = data?.data ?? []
  const meta = data?.meta

  return (
    <>
      <Card>
        <CardContent className="p-0">
          {loading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : results.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              Nessun risultato registrato.
            </div>
          ) : (
            <div className="divide-y">
              {results.map((r) => {
                const p1 = r.booking?.player1
                const p2 = r.booking?.player2
                const date = r.booking?.booking_date
                const p1Won = r.winner_id === p1?.id
                const p2Won = r.winner_id === p2?.id
                const draw = !r.winner_id

                return (
                  <div key={r.id} className="px-4 py-4">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs text-muted-foreground">
                        {date ? new Date(date).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' }) : '—'}
                        {r.booking && ` · ${r.booking.start_time}`}
                      </span>
                      <div className="flex items-center gap-2">
                        {r.score && <Badge variant="outline" className="font-mono text-xs">{r.score}</Badge>}
                        {!r.player1_confirmed || !r.player2_confirmed ? (
                          <Badge variant="secondary" className="text-[10px]">Da confermare</Badge>
                        ) : (
                          <Badge variant="default" className="text-[10px]">Confermato</Badge>
                        )}
                      </div>
                    </div>
                    <div className="grid grid-cols-[1fr_auto_1fr] gap-4 items-center">
                      {/* Player 1 */}
                      <div className={`text-right ${p1Won ? 'font-bold' : ''}`}>
                        <p className="text-sm">{p1?.name ?? '—'}</p>
                        <EloChange before={r.player1_elo_before} after={r.player1_elo_after} />
                      </div>
                      {/* VS */}
                      <div className="text-center">
                        <span className="text-xs font-medium text-muted-foreground">VS</span>
                      </div>
                      {/* Player 2 */}
                      <div className={`text-left ${p2Won ? 'font-bold' : ''}`}>
                        <p className="text-sm">{p2?.name ?? '—'}</p>
                        <EloChange before={r.player2_elo_before} after={r.player2_elo_after} />
                      </div>
                    </div>
                    {draw && (
                      <p className="text-xs text-center text-muted-foreground mt-1">Non giocata</p>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </CardContent>
      </Card>

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
    </>
  )
}

function EloRanking() {
  const { data, loading } = useApi<PaginatedResponse<User>>('/admin/users?sort=elo_rating&dir=desc&per_page=50')
  const users = data?.data ?? []

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base flex items-center gap-2">
          <Trophy className="h-4 w-4 text-amber-500" />
          Classifica ELO
        </CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {loading ? (
          <div className="flex justify-center py-12">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : users.length === 0 ? (
          <div className="py-12 text-center text-muted-foreground">Nessun giocatore.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="px-4 py-3 text-left font-medium w-12">#</th>
                  <th className="px-4 py-3 text-left font-medium">Giocatore</th>
                  <th className="px-4 py-3 text-left font-medium">Livello</th>
                  <th className="px-4 py-3 text-right font-medium">ELO</th>
                  <th className="px-4 py-3 text-right font-medium">Partite</th>
                  <th className="px-4 py-3 text-right font-medium">Vittorie</th>
                  <th className="px-4 py-3 text-right font-medium">Win%</th>
                </tr>
              </thead>
              <tbody>
                {users.map((u, i) => {
                  const winRate = u.matches_played > 0
                    ? Math.round((u.matches_won / u.matches_played) * 100)
                    : 0

                  return (
                    <tr key={u.id} className="border-b hover:bg-muted/30">
                      <td className="px-4 py-3">
                        {i < 3 ? (
                          <span className={`inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white ${
                            i === 0 ? 'bg-amber-500' : i === 1 ? 'bg-gray-400' : 'bg-amber-700'
                          }`}>
                            {i + 1}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">{i + 1}</span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <p className="font-medium">{u.name}</p>
                        {u.is_fit && <span className="text-xs text-muted-foreground">FIT {u.fit_rating}</span>}
                      </td>
                      <td className="px-4 py-3 capitalize text-muted-foreground">{u.self_level ?? '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <span className="font-bold text-base">{u.elo_rating}</span>
                      </td>
                      <td className="px-4 py-3 text-right">{u.matches_played}</td>
                      <td className="px-4 py-3 text-right">{u.matches_won}</td>
                      <td className="px-4 py-3 text-right">
                        <span className={winRate >= 50 ? 'text-emerald-600 font-medium' : 'text-muted-foreground'}>
                          {u.matches_played > 0 ? `${winRate}%` : '—'}
                        </span>
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
  )
}

function EloChange({ before, after }: { before: number; after: number }) {
  if (!before || !after) return null
  const delta = after - before
  if (delta === 0) return (
    <span className="text-[11px] text-muted-foreground flex items-center justify-end gap-0.5">
      <Minus className="h-3 w-3" /> {after}
    </span>
  )
  return (
    <span className={`text-[11px] flex items-center gap-0.5 ${delta > 0 ? 'text-emerald-600' : 'text-red-500'} ${delta > 0 ? 'justify-end' : 'justify-start'}`}>
      {delta > 0 ? <TrendingUp className="h-3 w-3" /> : <TrendingDown className="h-3 w-3" />}
      {after} ({delta > 0 ? '+' : ''}{delta})
    </span>
  )
}
