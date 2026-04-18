import { useState, useCallback, useEffect } from 'react'
import {
  Loader2, Zap, Info, Plus, Pencil, Trash2, Bell, Check,
  MessageCircle, Sparkles, Calendar, Clock, Eye, EyeOff, AlertTriangle,
} from 'lucide-react'
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

interface ReminderSlot { hours_before: number; enabled: boolean; flow_node_id?: number }
interface ReminderSettings { enabled: boolean; slots: ReminderSlot[] }

interface FlowNodeConfig { text?: string; buttons?: { label: string }[] }
interface FlowNodeData { id: number; config: FlowNodeConfig }

interface EnvConfig {
  whatsapp_phone_number_id: string
  whatsapp_verify_token: string
  whatsapp_token: string
  whatsapp_api_version: string
  gemini_model: string
  gemini_key: string
  gemini_timeout: string
  google_calendar_id: string
  app_timezone: string
  session_timeout_minutes: string
}

// ── Helpers ──────────────────────────────────────────────────────────

function fmtDuration(m: number): string {
  if (m === 60) return '1 ora'; if (m === 90) return '1,5 ore'; if (m === 120) return '2 ore'; if (m === 180) return '3 ore'; return `${m} min`
}

function PasswordInput({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder?: string }) {
  const [visible, setVisible] = useState(false)
  return (
    <div className="relative">
      <input
        type={visible ? 'text' : 'password'}
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        className={inputClass + ' pr-10'}
      />
      <button
        type="button"
        onClick={() => setVisible(v => !v)}
        className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:text-foreground transition-colors"
      >
        {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
      </button>
    </div>
  )
}

function SectionHeader({ icon, iconBg, title, tooltip }: {
  icon: React.ReactNode; iconBg: string; title: string; tooltip: string
}) {
  return (
    <CardTitle className="text-sm flex items-center gap-2">
      <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${iconBg}`}>
        {icon}
      </div>
      <div className="flex-1">
        <span>{title}</span>
        <p className="text-[10px] font-normal text-muted-foreground mt-0.5">{tooltip}</p>
      </div>
    </CardTitle>
  )
}

function SaveButton({ saving, saved, onClick, disabled }: {
  saving: boolean; saved: boolean; onClick: () => void; disabled?: boolean
}) {
  return (
    <div className="flex items-center gap-2 pt-2">
      <Button onClick={onClick} disabled={saving || disabled} size="sm" className="bg-emerald-600 hover:bg-emerald-700">
        {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : null}
        Salva
      </Button>
      {saved && (
        <span className="text-xs text-emerald-600 flex items-center gap-1 animate-in fade-in duration-200">
          <Check className="h-3 w-3" /> Salvato!
        </span>
      )}
    </div>
  )
}

// ── Main Component ───────────────────────────────────────────────────

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
        <p className="text-muted-foreground">Configurazione del circolo, integrazioni e notifiche.</p>
      </div>

      {/* ENV / Integration config cards */}
      <EnvConfigSection />

      {/* Reminder settings */}
      <ReminderConfig />

      {/* Pricing rules */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Regole Prezzi</h2>
        <Button onClick={openCreate} className="bg-emerald-600 hover:bg-emerald-700" size="sm">
          <Plus className="mr-1.5 h-4 w-4" /> Nuova regola
        </Button>
      </div>

      <Card className="overflow-hidden rounded-xl shadow-sm">
        <CardContent className="p-0">
          {loading ? (
            <div className="loading-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /><span className="text-xs text-muted-foreground">Caricamento regole...</span></div>
          ) : rules.length === 0 ? (
            <div className="py-16 text-center">
              <Info className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
              <p className="text-muted-foreground">Nessuna regola prezzi.</p>
              <p className="text-sm text-muted-foreground mt-1">Prezzo fallback: 20 euro.</p>
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
                        {rule.price !== null ? <span className="font-bold">&euro;{rule.price}</span>
                          : rule.price_per_hour !== null ? <span className="font-bold">&euro;{rule.price_per_hour}<span className="text-xs text-muted-foreground font-normal">/h</span></span>
                          : <span className="text-muted-foreground">&mdash;</span>}
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
          <FormField label="Prezzo fisso (euro)"><input type="number" step="0.01" value={form.price} onChange={e => set('price', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Prezzo/ora (euro)"><input type="number" step="0.01" value={form.price_per_hour} onChange={e => set('price_per_hour', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Peak">
            <select value={form.is_peak ? 'true' : 'false'} onChange={e => set('is_peak', e.target.value === 'true')} className={selectClass}>
              <option value="false">No</option><option value="true">Si</option>
            </select>
          </FormField>
          <FormField label="Priorità" hint="Piu alto = precedenza"><input type="number" value={form.priority} onChange={e => set('priority', e.target.value)} className={inputClass} /></FormField>
        </div>
      </FormDialog>

      <FormDialog open={!!deleting} onClose={() => setDeleting(null)} title="Elimina regola"
        onSubmit={handleDelete} submitting={submitting} submitLabel="Elimina" destructive>
        <p className="text-sm">Eliminare la regola <strong>{deleting?.label ?? `#${deleting?.id}`}</strong>?</p>
      </FormDialog>
    </div>
  )
}

// ── ENV Config Section ──────────────────────────────────────────────

function EnvConfigSection() {
  const [, setEnv] = useState<EnvConfig | null>(null)
  const [loading, setLoading] = useState(true)

  // Per-section local state
  const [waForm, setWaForm] = useState({ phone_number_id: '', token: '', verify_token: '', api_version: 'v21.0' })
  const [waSaving, setWaSaving] = useState(false)
  const [waSaved, setWaSaved] = useState(false)

  const [aiForm, setAiForm] = useState({ model: '', key: '', timeout: '30' })
  const [aiSaving, setAiSaving] = useState(false)
  const [aiSaved, setAiSaved] = useState(false)

  const [calForm, setCalForm] = useState({ calendar_id: '' })
  const [calSaving, setCalSaving] = useState(false)
  const [calSaved, setCalSaved] = useState(false)

  const [botForm, setBotForm] = useState({ session_timeout: '30', timezone: 'Europe/Rome' })
  const [botSaving, setBotSaving] = useState(false)
  const [botSaved, setBotSaved] = useState(false)

  useEffect(() => {
    apiFetch<EnvConfig>('/admin/settings/env')
      .then(data => {
        setEnv(data)
        setWaForm({
          phone_number_id: data.whatsapp_phone_number_id ?? '',
          token: data.whatsapp_token ?? '',
          verify_token: data.whatsapp_verify_token ?? '',
          api_version: data.whatsapp_api_version ?? 'v21.0',
        })
        setAiForm({
          model: data.gemini_model ?? '',
          key: data.gemini_key ?? '',
          timeout: data.gemini_timeout ?? '30',
        })
        setCalForm({ calendar_id: data.google_calendar_id ?? '' })
        setBotForm({
          session_timeout: data.session_timeout_minutes ?? '30',
          timezone: data.app_timezone ?? 'Europe/Rome',
        })
      })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const saveSection = async (
    section: 'whatsapp' | 'gemini' | 'calendar' | 'bot',
    payload: Record<string, string>,
    setSaving: (v: boolean) => void,
    setSaved: (v: boolean) => void,
  ) => {
    setSaving(true)
    setSaved(false)
    try {
      await apiFetch('/admin/settings/env', {
        method: 'PUT',
        body: JSON.stringify({ section, ...payload }),
      })
      setSaved(true)
      setTimeout(() => setSaved(false), 2000)
    } catch { /* */ }
    setSaving(false)
  }

  if (loading) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {[1, 2, 3, 4].map(i => (
          <Card key={i} className="rounded-xl shadow-sm"><CardContent className="loading-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /></CardContent></Card>
        ))}
      </div>
    )
  }

  return (
    <>
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
        <AlertTriangle className="h-4 w-4 text-amber-600 mt-0.5 shrink-0" />
        <p className="text-sm text-amber-800">Le modifiche alle credenziali hanno effetto immediato. Verifica i valori prima di salvare.</p>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* WhatsApp */}
        <Card>
          <CardHeader className="pb-3">
            <SectionHeader
              icon={<MessageCircle className="h-4 w-4 text-green-700" />}
              iconBg="bg-green-100"
              title="WhatsApp Business API"
              tooltip="Credenziali per l'invio messaggi WhatsApp Business API"
            />
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Phone Number ID</label>
              <input
                value={waForm.phone_number_id}
                onChange={e => setWaForm(f => ({ ...f, phone_number_id: e.target.value }))}
                className={inputClass}
                placeholder="es. 123456789012345"
              />
              <p className="text-xs text-muted-foreground">ID numerico del numero WhatsApp Business</p>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Token API</label>
              <PasswordInput
                value={waForm.token}
                onChange={v => setWaForm(f => ({ ...f, token: v }))}
                placeholder="Token di accesso permanente"
              />
              <p className="text-xs text-muted-foreground">Token di accesso per le API Meta Cloud</p>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Verify Token</label>
              <input
                value={waForm.verify_token}
                onChange={e => setWaForm(f => ({ ...f, verify_token: e.target.value }))}
                className={inputClass}
                placeholder="es. courtly_webhook_2026"
              />
              <p className="text-xs text-muted-foreground">Token di verifica per il webhook</p>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Versione API</label>
              <input
                value={waForm.api_version}
                onChange={e => setWaForm(f => ({ ...f, api_version: e.target.value }))}
                className={inputClass}
                placeholder="v21.0"
              />
              <p className="text-xs text-muted-foreground">Versione delle API Meta Graph (es. v21.0)</p>
            </div>
            <SaveButton
              saving={waSaving}
              saved={waSaved}
              onClick={() => saveSection('whatsapp', {
                whatsapp_phone_number_id: waForm.phone_number_id,
                whatsapp_token: waForm.token,
                whatsapp_verify_token: waForm.verify_token,
                whatsapp_api_version: waForm.api_version,
              }, setWaSaving, setWaSaved)}
            />
          </CardContent>
        </Card>

        {/* Gemini AI */}
        <Card>
          <CardHeader className="pb-3">
            <SectionHeader
              icon={<Sparkles className="h-4 w-4 text-purple-700" />}
              iconBg="bg-purple-100"
              title="Google Gemini AI"
              tooltip="Usato per interpretazione date e classificazione input utente"
            />
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Modello</label>
              <input
                value={aiForm.model}
                onChange={e => setAiForm(f => ({ ...f, model: e.target.value }))}
                className={inputClass}
                placeholder="es. gemini-2.5-flash"
              />
              <p className="text-xs text-muted-foreground">Nome del modello Gemini da utilizzare</p>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">API Key</label>
              <PasswordInput
                value={aiForm.key}
                onChange={v => setAiForm(f => ({ ...f, key: v }))}
                placeholder="Chiave API Google AI Studio"
              />
              <p className="text-xs text-muted-foreground">Chiave di autenticazione per le API Gemini</p>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Timeout (secondi)</label>
              <input
                type="number"
                min="5"
                max="120"
                value={aiForm.timeout}
                onChange={e => setAiForm(f => ({ ...f, timeout: e.target.value }))}
                className={inputClass}
                placeholder="30"
              />
              <p className="text-xs text-muted-foreground">Tempo massimo di attesa per una risposta da Gemini</p>
            </div>
            <SaveButton
              saving={aiSaving}
              saved={aiSaved}
              onClick={() => saveSection('gemini', {
                gemini_model: aiForm.model,
                gemini_key: aiForm.key,
                gemini_timeout: aiForm.timeout,
              }, setAiSaving, setAiSaved)}
            />
          </CardContent>
        </Card>

        {/* Google Calendar */}
        <Card>
          <CardHeader className="pb-3">
            <SectionHeader
              icon={<Calendar className="h-4 w-4 text-blue-700" />}
              iconBg="bg-blue-100"
              title="Google Calendar"
              tooltip="ID del calendario Google per le prenotazioni"
            />
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Calendar ID</label>
              <input
                value={calForm.calendar_id}
                onChange={e => setCalForm(f => ({ ...f, calendar_id: e.target.value }))}
                className={inputClass}
                placeholder="xxxxx@group.calendar.google.com"
              />
              <p className="text-xs text-muted-foreground">ID del calendario Google condiviso con il service account</p>
            </div>
            <SaveButton
              saving={calSaving}
              saved={calSaved}
              onClick={() => saveSection('calendar', {
                google_calendar_id: calForm.calendar_id,
              }, setCalSaving, setCalSaved)}
            />
          </CardContent>
        </Card>

        {/* Bot */}
        <Card>
          <CardHeader className="pb-3">
            <SectionHeader
              icon={<Zap className="h-4 w-4 text-orange-700" />}
              iconBg="bg-orange-100"
              title="Bot"
              tooltip="Dopo il timeout senza attivita, la sessione torna al menu principale"
            />
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Timeout sessione (minuti)</label>
              <input
                type="number"
                min="1"
                max="1440"
                value={botForm.session_timeout}
                onChange={e => setBotForm(f => ({ ...f, session_timeout: e.target.value }))}
                className={inputClass}
                placeholder="30"
              />
              <p className="text-xs text-muted-foreground">Minuti di inattivita prima che la sessione torni al menu</p>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Timezone</label>
              <input
                value={botForm.timezone}
                onChange={e => setBotForm(f => ({ ...f, timezone: e.target.value }))}
                className={inputClass}
                placeholder="Europe/Rome"
              />
              <p className="text-xs text-muted-foreground">Fuso orario per tutte le operazioni del bot</p>
            </div>
            <SaveButton
              saving={botSaving}
              saved={botSaved}
              onClick={() => saveSection('bot', {
                session_timeout_minutes: botForm.session_timeout,
                app_timezone: botForm.timezone,
              }, setBotSaving, setBotSaved)}
            />
          </CardContent>
        </Card>
      </div>
    </>
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

  if (loading) return <Card className="rounded-xl shadow-sm"><CardContent className="loading-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /><span className="text-xs text-muted-foreground">Caricamento...</span></CardContent></Card>

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <CardTitle className="text-sm flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100">
              <Bell className="h-4 w-4 text-amber-700" />
            </div>
            <div>
              <span>Promemoria prenotazioni</span>
              <p className="text-[10px] font-normal text-muted-foreground mt-0.5">Invia un messaggio WhatsApp ai giocatori prima della prenotazione</p>
            </div>
          </CardTitle>
          <div className="flex items-center gap-2">
            {saved && <span className="text-xs text-emerald-600 flex items-center gap-1"><Check className="h-3 w-3" /> Salvato!</span>}
            {saving && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
            <button
              onClick={toggleEnabled}
              className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${settings?.enabled ? 'bg-emerald-600' : 'bg-gray-300'}`}
            >
              <span className={`inline-block h-4 w-4 rounded-full bg-white transition-transform ${settings?.enabled ? 'translate-x-6' : 'translate-x-1'}`} />
            </button>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {!settings?.enabled ? (
          <p className="text-sm text-muted-foreground text-center py-4">Promemoria disabilitati.</p>
        ) : (
          <>
            <div className="space-y-3">
              {settings.slots.map((slot, i) => (
                <ReminderSlotCard
                  key={i}
                  slot={slot}
                  onToggle={() => toggleSlot(i)}
                  onRemove={() => removeSlot(i)}
                />
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

// ── Componente card per singolo slot reminder con editing inline ──────

function ReminderSlotCard({ slot, onToggle, onRemove }: {
  slot: ReminderSlot; onToggle: () => void; onRemove: () => void
}) {
  const [editing, setEditing] = useState(false)
  const [, setNodeData] = useState<FlowNodeData | null>(null)
  const [text, setText] = useState('')
  const [buttons, setButtons] = useState<string[]>([])
  const [saving, setSaving] = useState(false)
  const [loadingNode, setLoadingNode] = useState(false)

  const openEdit = async () => {
    if (!slot.flow_node_id) return
    if (editing) { setEditing(false); return }
    setLoadingNode(true)
    try {
      const node = await apiFetch<FlowNodeData>(`/admin/flow/nodes/${slot.flow_node_id}`)
      setNodeData(node)
      setText((node.config?.text as string) ?? '')
      setButtons(((node.config?.buttons as { label: string }[]) ?? []).map(b => b.label))
      setEditing(true)
    } catch {
      setNodeData({ id: slot.flow_node_id, config: {} })
      setText('')
      setButtons([])
      setEditing(true)
    } finally {
      setLoadingNode(false)
    }
  }

  const saveMessage = async () => {
    if (!slot.flow_node_id) return
    setSaving(true)
    try {
      await apiFetch(`/admin/flow/nodes/${slot.flow_node_id}`, {
        method: 'PUT',
        body: JSON.stringify({
          config: {
            text,
            buttons: buttons.filter(b => b.trim() !== '').map(label => ({ label })),
          },
        }),
      })
      setEditing(false)
    } catch (e) {
      alert(e instanceof Error ? e.message : 'Errore salvataggio')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rounded-lg border p-3">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <button
            onClick={onToggle}
            className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${slot.enabled ? 'bg-emerald-500' : 'bg-gray-300'}`}
          >
            <span className={`inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform ${slot.enabled ? 'translate-x-4.5' : 'translate-x-0.5'}`} />
          </button>
          <div>
            <p className="text-sm font-medium flex items-center gap-1.5">
              <Clock className="h-3.5 w-3.5 text-muted-foreground" />
              {slot.hours_before >= 24 ? `${Math.floor(slot.hours_before / 24)} giorno${slot.hours_before >= 48 ? 'i' : ''} prima` : `${slot.hours_before} ore prima`}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-1">
          {slot.flow_node_id && (
            <button
              onClick={openEdit}
              disabled={loadingNode}
              className="rounded px-2 py-1 text-xs text-emerald-600 hover:bg-emerald-50 font-medium transition-colors"
            >
              {loadingNode ? 'Carico...' : editing ? 'Chiudi' : 'Modifica messaggio'}
            </button>
          )}
          <button onClick={onRemove} className="rounded p-1 hover:bg-red-100 text-red-500 transition-colors">
            <Trash2 className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      {editing && (
        <div className="mt-3 space-y-3 border-t pt-3">
          <div>
            <label className="text-xs font-medium text-muted-foreground">Messaggio</label>
            <textarea
              value={text}
              onChange={e => setText(e.target.value)}
              rows={3}
              placeholder="Ciao {name}! Ti ricordo la prenotazione di {slot} 🎾"
              className={`${inputClass} !h-auto mt-1`}
            />
            <p className="text-[10px] text-muted-foreground mt-1">
              Variabili: <code>{'{name}'}</code> <code>{'{slot}'}</code> <code>{'{hours}'}</code>
            </p>
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Bottoni (max 3, 20 char)</label>
            <div className="space-y-1 mt-1">
              {buttons.map((btn, bi) => (
                <div key={bi} className="flex gap-1">
                  <input
                    value={btn}
                    onChange={e => {
                      const next = [...buttons]
                      next[bi] = e.target.value
                      setButtons(next)
                    }}
                    maxLength={20}
                    placeholder={`Bottone ${bi + 1}`}
                    className={`${inputClass} flex-1`}
                  />
                  <button onClick={() => setButtons(buttons.filter((_, j) => j !== bi))}
                    className="px-2 text-red-400 hover:text-red-600">✕</button>
                </div>
              ))}
              {buttons.length < 3 && (
                <button onClick={() => setButtons([...buttons, ''])}
                  className="w-full text-xs text-muted-foreground border border-dashed rounded py-1 hover:bg-muted/50">
                  + Aggiungi bottone
                </button>
              )}
            </div>
          </div>
          <div className="flex gap-2">
            <Button size="sm" onClick={saveMessage} disabled={saving}
              className="bg-emerald-600 hover:bg-emerald-700">
              {saving ? 'Salvo...' : 'Salva messaggio'}
            </Button>
            <Button size="sm" variant="outline" onClick={() => setEditing(false)}>Annulla</Button>
          </div>
        </div>
      )}
    </div>
  )
}
