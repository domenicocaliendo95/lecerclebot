import { useState, useEffect, useCallback } from 'react'
import { Loader2, Check, Search, MessageSquareText } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { apiFetch } from '@/hooks/use-api'

interface BotMessage {
  key: string
  text: string
  category: string
  description: string | null
}

type GroupedMessages = Record<string, BotMessage[]>

const categoryLabels: Record<string, string> = {
  onboarding: 'Onboarding',
  menu: 'Menu',
  prenotazione: 'Prenotazione',
  conferma: 'Conferma & Pagamento',
  gestione: 'Gestione Prenotazioni',
  profilo: 'Modifica Profilo',
  matchmaking: 'Matchmaking',
  risultati: 'Risultati Partita',
  feedback: 'Feedback',
  promemoria: 'Promemoria',
  errore: 'Errori',
}

const categoryOrder = Object.keys(categoryLabels)

// Evidenzia le variabili {nome} nel testo
function HighlightVars({ text }: { text: string }) {
  const parts = text.split(/(\{[a-z_]+\})/g)
  return (
    <>
      {parts.map((part, i) =>
        /^\{[a-z_]+\}$/.test(part)
          ? <span key={i} className="rounded bg-amber-100 px-1 py-0.5 text-amber-800 font-mono text-xs dark:bg-amber-900/30 dark:text-amber-300">{part}</span>
          : <span key={i}>{part}</span>
      )}
    </>
  )
}

export function Messaggi() {
  const [grouped, setGrouped] = useState<GroupedMessages>({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [editingKey, setEditingKey] = useState<string | null>(null)
  const [editText, setEditText] = useState('')
  const [saving, setSaving] = useState<string | null>(null)
  const [savedKey, setSavedKey] = useState<string | null>(null)

  const fetchMessages = useCallback(async () => {
    try {
      const data = await apiFetch<GroupedMessages>('/admin/bot-messages')
      setGrouped(data)
    } catch { /* */ }
    setLoading(false)
  }, [])

  useEffect(() => { fetchMessages() }, [fetchMessages])

  const startEdit = (msg: BotMessage) => {
    setEditingKey(msg.key)
    setEditText(msg.text)
  }

  const cancelEdit = () => {
    setEditingKey(null)
    setEditText('')
  }

  const saveEdit = async (key: string) => {
    setSaving(key)
    try {
      const updated = await apiFetch<BotMessage>(`/admin/bot-messages/${key}`, {
        method: 'PUT',
        body: JSON.stringify({ text: editText }),
      })
      // Aggiorna in-place
      setGrouped(prev => {
        const next = { ...prev }
        for (const cat of Object.keys(next)) {
          next[cat] = next[cat].map(m => m.key === key ? { ...m, text: updated.text } : m)
        }
        return next
      })
      setEditingKey(null)
      setSavedKey(key)
      setTimeout(() => setSavedKey(null), 2000)
    } catch { /* */ }
    setSaving(null)
  }

  const sortedCategories = Object.keys(grouped).sort(
    (a, b) => (categoryOrder.indexOf(a) === -1 ? 99 : categoryOrder.indexOf(a)) - (categoryOrder.indexOf(b) === -1 ? 99 : categoryOrder.indexOf(b))
  )

  const searchLower = search.toLowerCase()
  const filteredCategories = sortedCategories.map(cat => ({
    cat,
    messages: grouped[cat].filter(m =>
      !search ||
      m.key.toLowerCase().includes(searchLower) ||
      m.text.toLowerCase().includes(searchLower) ||
      (m.description ?? '').toLowerCase().includes(searchLower)
    ),
  })).filter(g => g.messages.length > 0)

  const totalMessages = Object.values(grouped).reduce((sum, msgs) => sum + msgs.length, 0)

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
        <h1 className="text-2xl font-bold tracking-tight">Messaggi Bot</h1>
        <p className="text-muted-foreground">
          {totalMessages} messaggi configurabili. Modifica il testo che il bot invia su WhatsApp.
        </p>
      </div>

      {/* Search */}
      <div className="relative max-w-md">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <input
          type="text"
          placeholder="Cerca per chiave, testo o descrizione..."
          value={search}
          onChange={e => setSearch(e.target.value)}
          className="w-full rounded-lg border bg-background py-2 pl-10 pr-4 text-sm outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
        />
      </div>

      {/* Info */}
      <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800/30 dark:bg-amber-900/10 dark:text-amber-300">
        Le variabili tra parentesi graffe (es. <code className="rounded bg-amber-100 px-1 font-mono text-xs dark:bg-amber-900/30">{'{name}'}</code>) vengono sostituite automaticamente dal bot. Non rimuoverle dal testo.
      </div>

      {/* Categories */}
      {filteredCategories.map(({ cat, messages }) => (
        <Card key={cat}>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <MessageSquareText className="h-4 w-4" />
              {categoryLabels[cat] ?? cat}
              <Badge variant="secondary" className="text-xs ml-1">{messages.length}</Badge>
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="divide-y">
              {messages.map(msg => (
                <div key={msg.key} className="px-4 py-3 hover:bg-muted/30 transition-colors">
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2 mb-1">
                        <code className="text-xs font-mono text-muted-foreground bg-muted px-1.5 py-0.5 rounded">{msg.key}</code>
                        {savedKey === msg.key && (
                          <span className="text-xs text-emerald-600 flex items-center gap-1">
                            <Check className="h-3 w-3" /> Salvato
                          </span>
                        )}
                      </div>
                      {msg.description && (
                        <p className="text-xs text-muted-foreground mb-1.5">{msg.description}</p>
                      )}

                      {editingKey === msg.key ? (
                        <div className="space-y-2">
                          <textarea
                            value={editText}
                            onChange={e => setEditText(e.target.value)}
                            rows={Math.max(3, editText.split('\n').length + 1)}
                            className="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 resize-y font-mono"
                            autoFocus
                          />
                          <div className="flex gap-2">
                            <Button
                              size="sm"
                              className="bg-emerald-600 hover:bg-emerald-700"
                              onClick={() => saveEdit(msg.key)}
                              disabled={saving === msg.key || editText === msg.text}
                            >
                              {saving === msg.key ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Check className="mr-1 h-3 w-3" />}
                              Salva
                            </Button>
                            <Button size="sm" variant="outline" onClick={cancelEdit}>
                              Annulla
                            </Button>
                          </div>
                        </div>
                      ) : (
                        <button
                          onClick={() => startEdit(msg)}
                          className="text-left text-sm whitespace-pre-wrap leading-relaxed hover:bg-muted/50 rounded px-2 py-1 -mx-2 -my-1 transition-colors w-full"
                          title="Clicca per modificare"
                        >
                          <HighlightVars text={msg.text} />
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      ))}

      {filteredCategories.length === 0 && (
        <div className="text-center py-16">
          <Search className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
          <p className="text-muted-foreground">Nessun messaggio trovato per "{search}"</p>
        </div>
      )}
    </div>
  )
}
