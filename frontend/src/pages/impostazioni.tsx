import { useState, useCallback } from 'react'
import { Loader2, Clock, Zap, Info, Plus, Pencil, Trash2 } from 'lucide-react'
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
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Impostazioni</h1>
          <p className="text-muted-foreground">Regole prezzi e configurazione del circolo.</p>
        </div>
        <Button onClick={openCreate} className="bg-emerald-600 hover:bg-emerald-700">
          <Plus className="mr-1.5 h-4 w-4" /> Nuova regola
        </Button>
      </div>

      <Card className="overflow-hidden">
        <CardHeader>
          <CardTitle className="text-base flex items-center gap-2"><Clock className="h-4 w-4" /> Regole Prezzi</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {loading ? (
            <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-muted-foreground" /></div>
          ) : rules.length === 0 ? (
            <div className="py-16 text-center">
              <Info className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
              <p className="text-muted-foreground">Nessuna regola prezzi configurata.</p>
              <p className="text-sm text-muted-foreground mt-1">Viene usato il prezzo di fallback (€20).</p>
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
          <FormField label="Prezzo fisso (€)" hint="Per slot"><input type="number" step="0.01" value={form.price} onChange={e => set('price', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Prezzo/ora (€)" hint="Alternativo al fisso"><input type="number" step="0.01" value={form.price_per_hour} onChange={e => set('price_per_hour', e.target.value)} className={inputClass} /></FormField>
          <FormField label="Peak">
            <select value={form.is_peak ? 'true' : 'false'} onChange={e => set('is_peak', e.target.value === 'true')} className={selectClass}>
              <option value="false">No</option><option value="true">Sì</option>
            </select>
          </FormField>
          <FormField label="Priorità" hint="Più alto = precedenza"><input type="number" value={form.priority} onChange={e => set('priority', e.target.value)} className={inputClass} /></FormField>
        </div>
      </FormDialog>

      {/* Delete Dialog */}
      <FormDialog open={!!deleting} onClose={() => setDeleting(null)} title="Elimina regola"
        onSubmit={handleDelete} submitting={submitting} submitLabel="Elimina" destructive>
        <p className="text-sm">Eliminare la regola <strong>{deleting?.label ?? `#${deleting?.id}`}</strong>?</p>
      </FormDialog>
    </div>
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
