import { useState, useCallback, useEffect } from 'react'
import { Loader2, Zap, Info, Plus, Pencil, Trash2, Bell, Check } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { FormDialog, FormField, inputClass, selectClass } from '@/components/ui/form-dialog'
import { useApi, apiFetch } from '@/hooks/use-api'
import type { PricingRule } from '@/types/api'

const dayNames: Record<number, string> = { 0: 'Domenica', 1: 'Lunedì', 2: 'Martedì', 3: 'Mercoledì', 4: 'Giovedì', 5: 'Venerdì', 6: 'Sabato' }

interface RuleForm {
  label: string; day_of_week: string; specific_date: string; start_time: string; end_time: string
  duration_minutes: string; price: string; price_per_hour: string; is_peak: boolean; priority: string
}
const emptyForm: RuleForm = { label: '', day_of_week: '', specific_date: '', start_time: '08:00', end_time: '22:00', duration_minutes: '', price: '', price_per_hour: '', is_peak: false, priority: '0' }

interface ReminderSlot { hours_before: number; enabled: boolean }
interface ReminderSettings { enabled: boolean; slots: ReminderSlot[] }

export function Impostazioni() {
  const { data, loading, refetch } = useApi<{ data: PricingRule[] }>('/admin/pricing-rules')
  const rules = data?.data ?? []

  const [showCreate, setShowCreate] = useState(false)
  const [editing, setEditing] = useState<PricingRule | null>(null)
  const [deleting, setDeleting] = useState<PricingRule | null>(null)
  const [form, setForm] = useState<RuleForm>(emptyForm)
  const [submitting, setSubmitting] = useState(false)

  const set = useCallback((k: keyof RuleForm, v: string | boolean) => setForm(f => ({ ...f, [k]: v })), [])

  const openCreate = () => { setForm(emptyForm); setShowCreate(true) }
  const openEdit = (r: PricingRule) => {
    setForm({
      label: r.label ?? '', day_of_week: r.day_of_week !== null ? String(r.day_of_week) : '',
      specific_date: r.specific_date ?? '', start_time: r.start_time, end_time: r.end_time,
      duration_minutes: r.duration_minutes ? String(r.duration_minutes) : '',
      price: r.price !== null ? String(r.price) : '', price_per_hour: r.price_per_hour !== null ? String(r.price_per_hour) : '',
      is_peak: r.is_peak, priority: String(r.priority),
    })
    setEditing(r)
  }

  const handleSave = async () => {
    setSubmitting(true)
    try {
      const body = {
        label: form.label || null,
        day_of_week: form.day_of_week !== '' ? Number(form.day_of_week) : null,
        specific_date: form.specific_date || null,
        start_time: form.start_time, end_time: form.end_time,
        duration_minutes: form.duration_minutes ? Number(form.duration_minutes) : null,
        price: form.price ? Number(form.price) : null,
        price_per_hour: form.price_per_hour ? Number(form.price_per_hour) : null,
        is_peak: form.is_peak, is_active: true, priority: Number(form.priority),
      }
      if (editing) {
        await apiFetch(`/admin/pricing-rules/${editing.id}`, { method: 'PUT', body: JSON.stringify(body) })
      } else {
        await apiFetch('/admin/pricing-rules', { method: 'POST', body: JSON.stringify(body) })
      }
      setShowCreate(false); setEditing(null); refetch()
    } catch { /* */ }
    setSubmitting(false)
  }

  const handleDelete = async () => {
    if (!deleting) return
    setSubmitting(true)
    try {
      await apiFetch(`/admin/pricing-rules/${deleting.id}`, { method: 'DELETE' })
      setDeleting(null); refetch()
    } catch { /* */ }
    setSubmitting(false)
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Impostazioni</h1>
        <p className="text-muted-foreground">Configurazione del circolo, prezzi e notifiche.</p>
      </div>

      {/* Reminder settings */}
      <ReminderConfig />

      {/* Pricing rules */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Regole Prezzi</h2>
        <Button onClick={openCreate} className="bg-emerald-600 hover:bg-emerald-700" size="sm">
          <Plus className="mr-1.5 h-4 w-4" /> Nuova regola
        </Button>
      </div>

      <Card className="overflow-hidden">
        <CardContent className="p-0">
          {loading ? (
            <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-muted-foreground" /></div>
          ) : rules.length === 0 ? (
            <div className="py-16 text-center">
              <Info className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
              <p className="text-muted-foreground">Nessuna regola prezzi.</p>
              <p className="text-sm text-muted-foreground mt-1">Prezzo fallback: €20.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b bg-muted/40">
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Regola</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Applicazione</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Orario</th>
                  <th className="px-4 py-3 text-left font-medium text-muted-foreground">Durata</th>
                  <th className="px-4 py-3 text-right font-medium text-muted-foreground">Prezzo</th>
                  <th className="px-4 py-3 text-center font-medium text-muted-foreground">Peak</th>
                  <th className="px-4 py-3 text-right font-medium text-muted-foreground">Priorità</th>
                  <th className="px-4 py-3 w-20" />
                </tr></thead>
                <tbody>
                  {rules.map((rule) => (
                    <tr key={rule.id} className="border-b hover:bg-muted/20 group transition-colors">
                      <td className="px-4 py-3 font-medium">{rule.label ?? `Regola #${rule.id}`}</td>
                      <td className="px-4 py-3">
                        {rule.specific_date ? <Badge variant="default" className="text-xs">{new Date(rule.specific_date).toLocaleDateString('it-IT', { day: 'numeric', month: 'short' })}</Badge>
                          : rule.day_of_week !== null ? <Badge variant="secondary" className="text-xs">{dayNames[rule.day_of_week]}</Badge>
                          : <span className="text-muted-foreground text-xs">Tutti i giorni</span>}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs">{rule.start_time} – {rule.end_time}</td>
                      <td className="px-4 py-3">{rule.duration_minutes ? fmtDuration(rule.duration_minutes) : <span className="text-muted-foreground text-xs">Qualsiasi</span>}</td>
                      <td className="px-4 py-3 text-right">
                        {rule.price !== null ? <span className="font-bold">€{rule.price}</span>
                          : rule.price_per_hour !== null ? <span className="font-bold">€{rule.price_per_hour}<span className="text-xs text-muted-foreground font-normal">/h</span></span>
                          : <span className="text-muted-foreground">—</span>}
                      </td>
                      <td className="px-4 py-3 text-center">{rule.is_peak && <Zap className="mx-auto h-4 w-4 text-amber-500" />}</td>
                      <td className="px-4 py-3 text-right text-muted-foreground">{rule.priority}</td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                          <button onClick={() => openEdit(rule)} className="rounded p-1 hover:bg-muted"><Pencil className="h-3.5 w-3.5" /></button>
                          <button onClick={() => setDeleting(rule)} className="rounded p-1 hover:bg-red-100 text-red-500"><Trash2 className="h-3.5 w-3.5" /></button>
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

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-3">
        <InfoCard title="Orari operativi" value="08:00 – 22:00" sub="Slot da 1h, 1.5h o 2h" />
        <InfoCard title="Timezone" value="Europe/Rome" sub="Tutte le prenotazioni in ora italiana" />
        <InfoCard title="Prezzo fallback" value="€20" sub="Se nessuna regola corrisponde" />
      </div>

      {/* Create / Edit Dialog */}
      <FormDialog open={showCreate || !!editing} onClose={() => { setShowCreate(false); setEditing(null) }}
        title={editing ? 'Modifica regola' : 'Nuova regola'} onSubmit={handleSave} submitting={submitting}>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Nome regola" hint="Opzionale"><input value={form.label} onChange={e => set('label', e.target.value)} className={inputClass} placeholder="es. Sera feriali" /></FormField>
          <FormField label="Giorno settimana" hint="Vuoto = tutti">
            <select value={form.day_of_week} onChange={e => set('day_of_week', e.target.value)} className={selectClass}>
              <option value="">Tutti i giorni</option>
              {Object.entries(dayNames).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </FormField>
          <FormField label="Data specifica" hint="Ha precedenza sul giorno"><input type="date" value={form.specific_date} onChange={e => set('specific_date', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Durata (min)">
            <select value={form.duration_minutes} onChange={e => set('duration_minutes', e.target.value)} className={selectClass}>
              <option value="">Qualsiasi</option><option value="60">1 ora</option><option value="90">1,5 ore</option><option value="120">2 ore</option><option value="180">3 ore</option>
            </select>
          </FormField>
          <FormField label="Ora inizio"><input type="time" value={form.start_time} onChange={e => set('start_time', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Ora fine"><input type="time" value={form.end_time} onChange={e => set('end_time', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Prezzo fisso (€)"><input type="number" step="0.01" value={form.price} onChange={e => set('price', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Prezzo/ora (€)"><input type="number" step="0.01" value={form.price_per_hour} onChange={e => set('price_per_hour', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Peak">
            <select value={form.is_peak ? 'true' : 'false'} onChange={e => set('is_peak', e.target.value === 'true')} className={selectClass}>
              <option value="false">No</option><option value="true">Sì</option>
            </select>
          </FormField>
          <FormField label="Priorità" hint="Più alto = precedenza"><input type="number" value={form.priority} onChange={e => set('priority', e.target.value)} className={inputClass} /></FormField>
        </div>
      </FormDialog>

      <FormDialog open={!!deleting} onClose={() => setDeleting(null)} title="Elimina regola"
        onSubmit={handleDelete} submitting={submitting} submitLabel="Elimina" destructive>
        <p className="text-sm">Eliminare la regola <strong>{deleting?.label ?? `#${deleting?.id}`}</strong>?</p>
      </FormDialog>
    </div>
  )
}

// ── Reminder Config ──────────────────────────────────────────────────

function ReminderConfig() {
  const [settings, setSettings] = useState<ReminderSettings | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [newHours, setNewHours] = useState('')

  useEffect(() => {
    apiFetch<{ key: string; value: ReminderSettings }>('/admin/settings/reminders')
      .then(res => setSettings(res.value))
      .catch(() => setSettings({ enabled: true, slots: [{ hours_before: 24, enabled: true }, { hours_before: 2, enabled: true }] }))
      .finally(() => setLoading(false))
  }, [])

  const save = async (updated: ReminderSettings) => {
    setSettings(updated)
    setSaving(true)
    setSaved(false)
    try {
      await apiFetch('/admin/settings/reminders', { method: 'PUT', body: JSON.stringify({ value: updated }) })
      setSaved(true)
      setTimeout(() => setSaved(false), 2000)
    } catch { /* */ }
    setSaving(false)
  }

  const toggleEnabled = () => {
    if (!settings) return
    save({ ...settings, enabled: !settings.enabled })
  }

  const toggleSlot = (index: number) => {
    if (!settings) return
    const slots = [...settings.slots]
    slots[index] = { ...slots[index], enabled: !slots[index].enabled }
    save({ ...settings, slots })
  }

  const removeSlot = (index: number) => {
    if (!settings) return
    save({ ...settings, slots: settings.slots.filter((_, i) => i !== index) })
  }

  const addSlot = () => {
    const hours = parseInt(newHours)
    if (!settings || isNaN(hours) || hours < 1 || hours > 168) return
    if (settings.slots.some(s => s.hours_before === hours)) return
    save({ ...settings, slots: [...settings.slots, { hours_before: hours, enabled: true }].sort((a, b) => b.hours_before - a.hours_before) })
    setNewHours('')
  }

  if (loading) return <Card><CardContent className="py-8 flex justify-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /></CardContent></Card>

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <CardTitle className="text-base flex items-center gap-2">
            <Bell className="h-4 w-4" /> Promemoria prenotazioni
          </CardTitle>
          <div className="flex items-center gap-2">
            {saved && <span className="text-xs text-emerald-600 flex items-center gap-1"><Check className="h-3 w-3" /> Salvato</span>}
            {saving && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
            <button
              onClick={toggleEnabled}
              className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${settings?.enabled ? 'bg-emerald-600' : 'bg-gray-300'}`}
            >
              <span className={`inline-block h-4 w-4 rounded-full bg-white transition-transform ${settings?.enabled ? 'translate-x-6' : 'translate-x-1'}`} />
            </button>
          </div>
        </div>
        <p className="text-sm text-muted-foreground">
          Invia un messaggio WhatsApp ai giocatori prima della prenotazione. Il comando gira ogni 15 minuti.
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        {!settings?.enabled ? (
          <p className="text-sm text-muted-foreground text-center py-4">Promemoria disabilitati.</p>
        ) : (
          <>
            <div className="space-y-2">
              {settings.slots.map((slot, i) => (
                <div key={i} className="flex items-center justify-between rounded-lg border p-3">
                  <div className="flex items-center gap-3">
                    <button
                      onClick={() => toggleSlot(i)}
                      className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${slot.enabled ? 'bg-emerald-500' : 'bg-gray-300'}`}
                    >
                      <span className={`inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform ${slot.enabled ? 'translate-x-4.5' : 'translate-x-0.5'}`} />
                    </button>
                    <div>
                      <p className="text-sm font-medium">
                        {slot.hours_before >= 24 ? `${Math.floor(slot.hours_before / 24)} giorno${slot.hours_before >= 48 ? 'i' : ''} prima` : `${slot.hours_before} ore prima`}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {slot.hours_before >= 12 ? 'Messaggio: "Hai una prenotazione domani..."' : 'Messaggio: "Ci siamo quasi! Tra poco..."'}
                      </p>
                    </div>
                  </div>
                  <button onClick={() => removeSlot(i)} className="rounded p-1 hover:bg-red-100 text-red-500 transition-colors">
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              ))}
            </div>

            <div className="flex items-center gap-2">
              <input type="number" min="1" max="168" placeholder="Ore prima (es. 12)" value={newHours}
                onChange={e => setNewHours(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && addSlot()}
                className={`${inputClass} max-w-48`} />
              <Button onClick={addSlot} size="sm" variant="outline" disabled={!newHours}>
                <Plus className="mr-1 h-3.5 w-3.5" /> Aggiungi
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}

function InfoCard({ title, value, sub }: { title: string; value: string; sub: string }) {
  return (
    <Card>
      <CardHeader className="pb-2"><CardTitle className="text-sm text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent><p className="text-2xl font-bold">{value}</p><p className="text-xs text-muted-foreground mt-1">{sub}</p></CardContent>
    </Card>
  )
}

function fmtDuration(m: number): string {
  if (m === 60) return '1 ora'; if (m === 90) return '1,5 ore'; if (m === 120) return '2 ore'; if (m === 180) return '3 ore'; return `${m} min`
}
