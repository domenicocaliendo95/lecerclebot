import { Loader2, Clock, Zap, Info } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useApi } from '@/hooks/use-api'
import type { PricingRule } from '@/types/api'

const dayNames: Record<number, string> = {
  0: 'Domenica',
  1: 'Lunedì',
  2: 'Martedì',
  3: 'Mercoledì',
  4: 'Giovedì',
  5: 'Venerdì',
  6: 'Sabato',
}

export function Impostazioni() {
  const { data, loading } = useApi<{ data: PricingRule[] }>('/admin/pricing-rules')
  const rules = data?.data ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Impostazioni</h1>
        <p className="text-muted-foreground">Regole prezzi e configurazione del circolo.</p>
      </div>

      {/* Pricing rules */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base flex items-center gap-2">
            <Clock className="h-4 w-4" />
            Regole Prezzi
          </CardTitle>
          <p className="text-sm text-muted-foreground">
            Le regole sono ordinate per priorità. Regole con data specifica hanno precedenza sui giorni della settimana, che hanno precedenza sulle regole generiche.
          </p>
        </CardHeader>
        <CardContent className="p-0">
          {loading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : rules.length === 0 ? (
            <div className="py-12 text-center">
              <Info className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
              <p className="text-muted-foreground">Nessuna regola prezzi configurata.</p>
              <p className="text-sm text-muted-foreground mt-1">Viene usato il prezzo di fallback (€20).</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="px-4 py-3 text-left font-medium">Regola</th>
                    <th className="px-4 py-3 text-left font-medium">Applicazione</th>
                    <th className="px-4 py-3 text-left font-medium">Orario</th>
                    <th className="px-4 py-3 text-left font-medium">Durata</th>
                    <th className="px-4 py-3 text-right font-medium">Prezzo</th>
                    <th className="px-4 py-3 text-center font-medium">Peak</th>
                    <th className="px-4 py-3 text-right font-medium">Priorità</th>
                  </tr>
                </thead>
                <tbody>
                  {rules.map((rule) => (
                    <tr key={rule.id} className="border-b hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <span className="font-medium">{rule.label ?? `Regola #${rule.id}`}</span>
                      </td>
                      <td className="px-4 py-3">
                        {rule.specific_date ? (
                          <Badge variant="default" className="text-xs">
                            {new Date(rule.specific_date).toLocaleDateString('it-IT', { day: 'numeric', month: 'short' })}
                          </Badge>
                        ) : rule.day_of_week !== null ? (
                          <Badge variant="secondary" className="text-xs">
                            {dayNames[rule.day_of_week] ?? `Giorno ${rule.day_of_week}`}
                          </Badge>
                        ) : (
                          <span className="text-muted-foreground text-xs">Tutti i giorni</span>
                        )}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs">
                        {rule.start_time} – {rule.end_time}
                      </td>
                      <td className="px-4 py-3">
                        {rule.duration_minutes ? (
                          <span>{formatDuration(rule.duration_minutes)}</span>
                        ) : (
                          <span className="text-muted-foreground text-xs">Qualsiasi</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {rule.price !== null ? (
                          <span className="font-bold">€{rule.price}</span>
                        ) : rule.price_per_hour !== null ? (
                          <span className="font-bold">€{rule.price_per_hour}<span className="text-xs text-muted-foreground font-normal">/h</span></span>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-center">
                        {rule.is_peak && <Zap className="mx-auto h-4 w-4 text-amber-500" />}
                      </td>
                      <td className="px-4 py-3 text-right text-muted-foreground">
                        {rule.priority}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">Orari operativi</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">08:00 – 22:00</p>
            <p className="text-xs text-muted-foreground mt-1">Slot da 1h, 1.5h o 2h</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">Timezone</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">Europe/Rome</p>
            <p className="text-xs text-muted-foreground mt-1">Tutte le prenotazioni in ora italiana</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">Prezzo fallback</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">€20</p>
            <p className="text-xs text-muted-foreground mt-1">Se nessuna regola corrisponde</p>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function formatDuration(minutes: number): string {
  if (minutes === 60) return '1 ora'
  if (minutes === 90) return '1,5 ore'
  if (minutes === 120) return '2 ore'
  if (minutes === 180) return '3 ore'
  return `${minutes} min`
}
