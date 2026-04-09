import { useState, useEffect, useCallback } from 'react'
import { Loader2, Check, Search, ArrowRight, Pencil, Cpu, Cog, X, Save, MessageSquareText } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { apiFetch } from '@/hooks/use-api'

interface FlowButton {
  label: string
  target_state: string
  value?: string
  side_effect?: string
}

interface FlowState {
  state: string
  type: 'simple' | 'complex'
  message_key: string
  fallback_key: string | null
  buttons: FlowButton[] | null
  category: string
  description: string | null
  sort_order: number
  message_text: string | null
  fallback_text: string | null
}

type GroupedFlowStates = Record<string, FlowState[]>

const categoryLabels: Record<string, string> = {
  onboarding: 'Onboarding',
  menu: 'Menu',
  prenotazione: 'Prenotazione',
  conferma: 'Conferma & Pagamento',
  matchmaking: 'Matchmaking',
  gestione: 'Gestione Prenotazioni',
  profilo: 'Modifica Profilo',
  risultati: 'Risultati Partita',
  feedback: 'Feedback',
}

const categoryColors: Record<string, string> = {
  onboarding: 'border-l-blue-500',
  menu: 'border-l-emerald-500',
  prenotazione: 'border-l-amber-500',
  conferma: 'border-l-purple-500',
  matchmaking: 'border-l-orange-500',
  gestione: 'border-l-cyan-500',
  profilo: 'border-l-pink-500',
  risultati: 'border-l-red-500',
  feedback: 'border-l-yellow-500',
}

const categoryOrder = Object.keys(categoryLabels)

function StateTypeBadge({ type }: { type: 'simple' | 'complex' }) {
  return type === 'simple' ? (
    <Badge variant="secondary" className="text-[10px] gap-1"><Cog className="h-2.5 w-2.5" /> Configurabile</Badge>
  ) : (
    <Badge variant="outline" className="text-[10px] gap-1 border-amber-300 text-amber-700 dark:text-amber-400"><Cpu className="h-2.5 w-2.5" /> Logica custom</Badge>
  )
}

function HighlightVars({ text }: { text: string }) {
  const parts = text.split(/(\{[a-z_]+\})/g)
  return (
    <>{parts.map((part, i) =>
      /^\{[a-z_]+\}$/.test(part)
        ? <span key={i} className="rounded bg-amber-100 px-0.5 text-amber-800 font-mono text-[10px] dark:bg-amber-900/30 dark:text-amber-300">{part}</span>
        : <span key={i}>{part}</span>
    )}</>
  )
}

function ButtonEditor({ flowState, onSave, saving }: {
  flowState: FlowState
  onSave: (buttons: FlowButton[]) => void
  saving: boolean
}) {
  const [buttons, setButtons] = useState<FlowButton[]>(flowState.buttons ?? [])
  const [dirty, setDirty] = useState(false)

  const updateButton = (index: number, field: keyof FlowButton, value: string) => {
    setButtons(prev => prev.map((b, i) => i === index ? { ...b, [field]: value } : b))
    setDirty(true)
  }

  const removeButton = (index: number) => {
    setButtons(prev => prev.filter((_, i) => i !== index))
    setDirty(true)
  }

  const addButton = () => {
    if (buttons.length >= 3) return
    setButtons(prev => [...prev, { label: '', target_state: '', value: '' }])
    setDirty(true)
  }

  return (
    <div className="space-y-3 mt-3">
      <p className="text-xs font-medium text-muted-foreground">Pulsanti WhatsApp (max 3, max 20 char)</p>
      {buttons.map((btn, i) => (
        <div key={i} className="flex items-start gap-2">
          <div className="flex-1 grid grid-cols-2 gap-2">
            <input
              value={btn.label}
              onChange={e => updateButton(i, 'label', e.target.value)}
              maxLength={20}
              placeholder="Label"
              className="rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
            />
            <div className="flex items-center gap-1">
              <ArrowRight className="h-3 w-3 text-muted-foreground shrink-0" />
              <input
                value={btn.target_state}
                onChange={e => updateButton(i, 'target_state', e.target.value)}
                placeholder="Stato destinazione"
                className="rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 w-full font-mono"
              />
            </div>
          </div>
          <button onClick={() => removeButton(i)} className="p-1 text-red-400 hover:text-red-600 mt-0.5">
            <X className="h-3 w-3" />
          </button>
        </div>
      ))}
      <div className="flex gap-2">
        {buttons.length < 3 && (
          <Button size="sm" variant="outline" onClick={addButton} className="text-xs h-7">
            + Aggiungi pulsante
          </Button>
        )}
        {dirty && (
          <Button
            size="sm"
            className="bg-emerald-600 hover:bg-emerald-700 text-xs h-7"
            onClick={() => { onSave(buttons); setDirty(false) }}
            disabled={saving}
          >
            {saving ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Save className="mr-1 h-3 w-3" />}
            Salva pulsanti
          </Button>
        )}
      </div>
    </div>
  )
}

function FlowStateCard({ flowState, allStates, onUpdate }: {
  flowState: FlowState
  allStates: FlowState[]
  onUpdate: () => void
}) {
  const [expanded, setExpanded] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)

  const saveButtons = async (buttons: FlowButton[]) => {
    setSaving(true)
    try {
      await apiFetch(`/admin/bot-flow-states/${flowState.state}`, {
        method: 'PUT',
        body: JSON.stringify({ buttons }),
      })
      setSaved(true)
      setTimeout(() => setSaved(false), 2000)
      onUpdate()
    } catch { /* */ }
    setSaving(false)
  }

  const targetStates = (flowState.buttons ?? []).map(b => b.target_state)
  const hasAiClassification = flowState.type === 'simple' || (flowState.buttons?.length ?? 0) > 0

  return (
    <div
      className={`rounded-lg border border-l-4 ${categoryColors[flowState.category] ?? 'border-l-gray-400'} bg-card transition-all hover:shadow-sm`}
    >
      <div
        className="px-4 py-3 cursor-pointer select-none"
        onClick={() => setExpanded(!expanded)}
      >
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <code className="text-xs font-bold font-mono">{flowState.state}</code>
              <StateTypeBadge type={flowState.type} />
              {hasAiClassification && (
                <Badge variant="outline" className="text-[10px] gap-1 border-violet-300 text-violet-600 dark:text-violet-400">
                  AI fallback
                </Badge>
              )}
              {saved && (
                <span className="text-xs text-emerald-600 flex items-center gap-1">
                  <Check className="h-3 w-3" /> Salvato
                </span>
              )}
            </div>
            {flowState.description && (
              <p className="text-xs text-muted-foreground mt-0.5">{flowState.description}</p>
            )}
          </div>
          <Pencil className={`h-3.5 w-3.5 text-muted-foreground shrink-0 mt-0.5 transition-transform ${expanded ? 'rotate-45' : ''}`} />
        </div>

        {/* Messaggio preview */}
        {flowState.message_text && (
          <div className="mt-2 text-xs text-muted-foreground bg-muted/50 rounded px-2 py-1.5 line-clamp-2">
            <HighlightVars text={flowState.message_text} />
          </div>
        )}

        {/* Bottoni e connessioni (compact) */}
        {!expanded && flowState.buttons && flowState.buttons.length > 0 && (
          <div className="flex flex-wrap gap-1.5 mt-2">
            {flowState.buttons.map((btn, i) => (
              <span key={i} className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10px]">
                {btn.label}
                <ArrowRight className="h-2.5 w-2.5 text-muted-foreground" />
                <span className="font-mono text-muted-foreground">{btn.target_state}</span>
              </span>
            ))}
          </div>
        )}
      </div>

      {/* Expanded: editor */}
      {expanded && (
        <div className="px-4 pb-4 border-t pt-3">
          {/* Messaggio */}
          <div className="space-y-1.5">
            <p className="text-xs font-medium text-muted-foreground flex items-center gap-1">
              <MessageSquareText className="h-3 w-3" />
              Messaggio: <code className="font-mono bg-muted px-1 rounded">{flowState.message_key}</code>
            </p>
            {flowState.message_text && (
              <div className="text-sm whitespace-pre-wrap bg-muted/30 rounded-md px-3 py-2 border">
                <HighlightVars text={flowState.message_text} />
              </div>
            )}
            <p className="text-[10px] text-muted-foreground">
              Per modificare il testo, vai alla pagina <a href="/panel/messaggi" className="text-emerald-600 underline">Messaggi Bot</a>.
            </p>
          </div>

          {/* Fallback */}
          {flowState.fallback_key && (
            <div className="mt-3 space-y-1">
              <p className="text-xs font-medium text-muted-foreground">
                Fallback (input non capito): <code className="font-mono bg-muted px-1 rounded">{flowState.fallback_key}</code>
              </p>
              {flowState.fallback_text && (
                <div className="text-xs text-muted-foreground bg-red-50 dark:bg-red-950/20 rounded px-2 py-1.5 border border-red-200 dark:border-red-800/30">
                  <HighlightVars text={flowState.fallback_text} />
                </div>
              )}
            </div>
          )}

          {/* Bottoni editor */}
          {flowState.type === 'simple' ? (
            <ButtonEditor flowState={flowState} onSave={saveButtons} saving={saving} />
          ) : flowState.buttons && flowState.buttons.length > 0 ? (
            <div className="mt-3">
              <p className="text-xs font-medium text-muted-foreground mb-2">
                Pulsanti (gestiti dal codice — le label sono editabili)
              </p>
              <ButtonEditor flowState={flowState} onSave={saveButtons} saving={saving} />
            </div>
          ) : (
            <div className="mt-3 text-xs text-muted-foreground italic">
              Nessun pulsante — input libero (testo)
            </div>
          )}

          {/* Connessioni in uscita */}
          {targetStates.length > 0 && (
            <div className="mt-3 pt-2 border-t">
              <p className="text-[10px] font-medium text-muted-foreground mb-1">Transizioni in uscita:</p>
              <div className="flex flex-wrap gap-1">
                {[...new Set(targetStates)].map(ts => {
                  const target = allStates.find(s => s.state === ts)
                  return (
                    <span key={ts} className="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[10px] font-mono">
                      <ArrowRight className="h-2.5 w-2.5 text-emerald-500" />
                      {ts}
                      {target && <span className="text-muted-foreground font-sans">({categoryLabels[target.category] ?? target.category})</span>}
                    </span>
                  )
                })}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export function Flusso() {
  const [grouped, setGrouped] = useState<GroupedFlowStates>({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  const fetchStates = useCallback(async () => {
    try {
      const data = await apiFetch<GroupedFlowStates>('/admin/bot-flow-states')
      setGrouped(data)
    } catch { /* */ }
    setLoading(false)
  }, [])

  useEffect(() => { fetchStates() }, [fetchStates])

  const allStates = Object.values(grouped).flat()

  const sortedCategories = Object.keys(grouped).sort(
    (a, b) => (categoryOrder.indexOf(a) === -1 ? 99 : categoryOrder.indexOf(a)) - (categoryOrder.indexOf(b) === -1 ? 99 : categoryOrder.indexOf(b))
  )

  const searchLower = search.toLowerCase()
  const filteredCategories = sortedCategories.map(cat => ({
    cat,
    states: grouped[cat].filter(s =>
      !search ||
      s.state.toLowerCase().includes(searchLower) ||
      (s.description ?? '').toLowerCase().includes(searchLower) ||
      (s.message_text ?? '').toLowerCase().includes(searchLower) ||
      (s.buttons ?? []).some(b => b.label.toLowerCase().includes(searchLower))
    ),
  })).filter(g => g.states.length > 0)

  const totalStates = allStates.length
  const simpleCount = allStates.filter(s => s.type === 'simple').length

  if (loading) {
    return (
      <div className="flex justify-center py-24">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Flusso Conversazione</h1>
        <p className="text-muted-foreground">
          {totalStates} stati ({simpleCount} configurabili, {totalStates - simpleCount} con logica custom).
          Modifica pulsanti e transizioni del bot.
        </p>
      </div>

      {/* Search */}
      <div className="relative max-w-md">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <input
          type="text"
          placeholder="Cerca stati, pulsanti, messaggi..."
          value={search}
          onChange={e => setSearch(e.target.value)}
          className="w-full rounded-lg border bg-background py-2 pl-10 pr-4 text-sm outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
        />
      </div>

      {/* Legend */}
      <div className="flex flex-wrap gap-3 text-xs">
        <span className="flex items-center gap-1.5">
          <Badge variant="secondary" className="text-[10px] gap-1"><Cog className="h-2.5 w-2.5" /> Configurabile</Badge>
          Pulsanti e transizioni editabili
        </span>
        <span className="flex items-center gap-1.5">
          <Badge variant="outline" className="text-[10px] gap-1 border-amber-300 text-amber-700"><Cpu className="h-2.5 w-2.5" /> Logica custom</Badge>
          Logica nel codice, label pulsanti editabili
        </span>
        <span className="flex items-center gap-1.5">
          <Badge variant="outline" className="text-[10px] gap-1 border-violet-300 text-violet-600">AI fallback</Badge>
          Se l'utente non clicca un pulsante, l'AI classifica l'intento
        </span>
      </div>

      {/* Flow categories */}
      {filteredCategories.map(({ cat, states }) => (
        <Card key={cat} className="overflow-hidden">
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <div className={`w-3 h-3 rounded-full ${categoryColors[cat]?.replace('border-l-', 'bg-') ?? 'bg-gray-400'}`} />
              {categoryLabels[cat] ?? cat}
              <Badge variant="secondary" className="text-xs ml-1">{states.length}</Badge>
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {states.map(flowState => (
              <FlowStateCard
                key={flowState.state}
                flowState={flowState}
                allStates={allStates}
                onUpdate={fetchStates}
              />
            ))}
          </CardContent>
        </Card>
      ))}

      {filteredCategories.length === 0 && (
        <div className="text-center py-16">
          <Search className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
          <p className="text-muted-foreground">Nessuno stato trovato per "{search}"</p>
        </div>
      )}
    </div>
  )
}
