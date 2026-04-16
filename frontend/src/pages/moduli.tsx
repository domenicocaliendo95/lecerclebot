import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiFetch, useApi } from '@/hooks/use-api'
import { FieldEditor, type ConfigField } from '@/components/flow/field-editor'
import { Plus, X, Save, Trash2, Package, Sparkles, Info, Search, Layers, ArrowRight } from 'lucide-react'

/* ────────────────── Tipi condivisi col backend ────────────────── */

type ModuleType = 'builtin' | 'preset'

type ModuleRow = {
  key: string
  label: string
  category: string
  description: string
  icon: string
  config_schema: Record<string, ConfigField>
  type: ModuleType
  enabled: boolean
  base_module_key?: string
  config_defaults?: Record<string, unknown>
}

type BuiltinRef = { key: string; label: string; category: string }

type CatalogResponse = {
  modules: ModuleRow[]
  builtins: BuiltinRef[]
}

type Preset = {
  id: number
  key: string
  base_module_key: string
  label: string
  description: string | null
  icon: string | null
  category: string | null
  config_defaults: Record<string, unknown>
}

/* ────────────────── Colori per categoria ────────────────── */

const CATEGORY_COLORS: Record<string, { bg: string; text: string; dot: string }> = {
  trigger: { bg: 'bg-emerald-50',  text: 'text-emerald-700', dot: 'bg-emerald-500' },
  logica:  { bg: 'bg-violet-50',   text: 'text-violet-700',  dot: 'bg-violet-500' },
  invio:   { bg: 'bg-sky-50',      text: 'text-sky-700',     dot: 'bg-sky-500' },
  attesa:  { bg: 'bg-amber-50',    text: 'text-amber-700',   dot: 'bg-amber-500' },
  dati:    { bg: 'bg-slate-50',    text: 'text-slate-700',   dot: 'bg-slate-500' },
  azione:  { bg: 'bg-rose-50',     text: 'text-rose-700',    dot: 'bg-rose-500' },
  ai:      { bg: 'bg-fuchsia-50',  text: 'text-fuchsia-700', dot: 'bg-fuchsia-500' },
}
const catColors = (c: string) => CATEGORY_COLORS[c] ?? { bg: 'bg-zinc-50', text: 'text-zinc-700', dot: 'bg-zinc-500' }

/* ────────────────── Pagina principale ────────────────── */

export function Moduli() {
  const [tab, setTab] = useState<'catalog' | 'presets' | 'composites'>('catalog')

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <header className="mb-6">
        <h1 className="text-2xl font-semibold text-zinc-900">Moduli</h1>
        <p className="text-sm text-zinc-500 mt-1">
          Gestisci i blocchi disponibili per costruire i flussi. Attiva quelli che ti servono, crea
          preset con config preimpostata, oppure impacchetta un sotto-grafo come modulo riusabile.
        </p>
      </header>

      <div className="flex gap-1 border-b border-zinc-200 mb-6">
        <TabButton active={tab === 'catalog'}    onClick={() => setTab('catalog')}    icon={<Package className="w-4 h-4" />}   label="Catalogo" />
        <TabButton active={tab === 'presets'}    onClick={() => setTab('presets')}    icon={<Sparkles className="w-4 h-4" />}  label="Preset" />
        <TabButton active={tab === 'composites'} onClick={() => setTab('composites')} icon={<Layers className="w-4 h-4" />}    label="Compositi" />
      </div>

      {tab === 'catalog'    && <CatalogTab />}
      {tab === 'presets'    && <PresetsTab />}
      {tab === 'composites' && <CompositesTab />}
    </div>
  )
}

function TabButton({ active, onClick, icon, label }: { active: boolean; onClick: () => void; icon: React.ReactNode; label: string }) {
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
        active ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-zinc-500 hover:text-zinc-800'
      }`}
    >
      {icon}
      {label}
    </button>
  )
}

/* ────────────────── Tab Catalogo ────────────────── */

function CatalogTab() {
  const { data, loading, refetch } = useApi<CatalogResponse>('/admin/flow/catalog')
  const [dirty, setDirty] = useState<Record<string, boolean>>({})
  const [search, setSearch] = useState('')
  const [saving, setSaving] = useState(false)

  const toggle = (key: string, current: boolean) => {
    setDirty((d) => ({ ...d, [key]: !current }))
  }

  const save = async () => {
    if (Object.keys(dirty).length === 0) return
    setSaving(true)
    try {
      await apiFetch('/admin/flow/catalog/toggles', {
        method: 'PUT',
        body: JSON.stringify({ toggles: dirty }),
      })
      setDirty({})
      refetch()
    } finally {
      setSaving(false)
    }
  }

  const filtered = useMemo(() => {
    if (!data) return []
    const q = search.trim().toLowerCase()
    return data.modules.filter((m) =>
      q === '' || m.label.toLowerCase().includes(q) || m.description.toLowerCase().includes(q) || m.key.toLowerCase().includes(q),
    )
  }, [data, search])

  const grouped = useMemo(() => {
    const g: Record<string, ModuleRow[]> = {}
    for (const m of filtered) {
      g[m.category] ??= []
      g[m.category].push(m)
    }
    return g
  }, [filtered])

  if (loading) return <div className="text-sm text-zinc-500">Caricamento...</div>
  if (!data) return <div className="text-sm text-rose-600">Errore nel caricamento</div>

  const dirtyCount = Object.keys(dirty).length
  const total = data.modules.length
  const enabled = data.modules.filter((m) => (dirty[m.key] ?? m.enabled)).length

  return (
    <div>
      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-3 mb-4 sticky top-0 bg-zinc-50/80 backdrop-blur z-10 py-2 -mx-1 px-1">
        <div className="relative flex-1 min-w-[240px]">
          <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cerca modulo..."
            className="w-full pl-9 pr-3 py-2 text-sm border border-zinc-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white"
          />
        </div>
        <div className="text-xs text-zinc-500">
          {enabled} / {total} attivi
        </div>
        {dirtyCount > 0 && (
          <button
            onClick={save}
            disabled={saving}
            className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg disabled:opacity-50"
          >
            <Save className="w-4 h-4" />
            {saving ? 'Salvo...' : `Salva ${dirtyCount} modifiche`}
          </button>
        )}
      </div>

      {/* Griglia per categoria */}
      {Object.entries(grouped)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([cat, mods]) => {
        const c = catColors(cat)
        return (
          <div key={cat} className="mb-6">
            <div className="flex items-center gap-2 mb-2">
              <span className={`w-2 h-2 rounded-full ${c.dot}`} />
              <h2 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">{cat}</h2>
              <span className="text-xs text-zinc-400">· {mods.length}</span>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
              {mods.map((m) => {
                const isEnabled = dirty[m.key] ?? m.enabled
                const isDirty = dirty[m.key] !== undefined
                return (
                  <div
                    key={m.key}
                    className={`relative p-4 bg-white border rounded-lg transition-all ${
                      isDirty ? 'border-amber-300 shadow-sm' : 'border-zinc-200'
                    } ${!isEnabled ? 'opacity-60' : ''}`}
                  >
                    {m.type === 'preset' && (
                      <span className="absolute top-2 right-2 inline-flex items-center gap-1 text-[10px] font-medium text-fuchsia-700 bg-fuchsia-50 px-1.5 py-0.5 rounded">
                        <Sparkles className="w-3 h-3" />
                        preset
                      </span>
                    )}
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 flex-1">
                        <div className="font-medium text-zinc-900 text-sm truncate">{m.label}</div>
                        <div className="text-[11px] font-mono text-zinc-400 mt-0.5 truncate">{m.key}</div>
                      </div>
                      <Switch checked={isEnabled} onChange={() => toggle(m.key, m.enabled)} />
                    </div>
                    <p className="text-xs text-zinc-600 mt-2 line-clamp-3 leading-snug">{m.description}</p>
                    {m.type === 'preset' && m.base_module_key && (
                      <div className="mt-2 text-[11px] text-zinc-500">
                        base: <span className="font-mono text-zinc-600">{m.base_module_key}</span>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          </div>
        )
        })}
    </div>
  )
}

function Switch({ checked, onChange }: { checked: boolean; onChange: () => void }) {
  return (
    <button
      onClick={onChange}
      type="button"
      className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${
        checked ? 'bg-emerald-500' : 'bg-zinc-300'
      }`}
    >
      <span
        className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
          checked ? 'translate-x-4' : 'translate-x-0.5'
        }`}
      />
    </button>
  )
}

/* ────────────────── Tab Preset ────────────────── */

function PresetsTab() {
  const { data: catalog } = useApi<CatalogResponse>('/admin/flow/catalog')
  const { data: presets, refetch } = useApi<{ presets: Preset[] }>('/admin/flow/presets')
  const [editingId, setEditingId] = useState<number | 'new' | null>(null)

  const openNew = () => setEditingId('new')
  const openEdit = (p: Preset) => setEditingId(p.id)

  if (!catalog || !presets) return <div className="text-sm text-zinc-500">Caricamento...</div>

  const editing = editingId === 'new' ? null : presets.presets.find((p) => p.id === editingId) ?? null
  const isEditing = editingId !== null

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <p className="text-sm text-zinc-600 flex items-start gap-2">
          <Info className="w-4 h-4 text-zinc-400 mt-0.5 shrink-0" />
          I preset sono moduli custom con configurazione preimpostata su uno esistente.
          Es. "Chiedi nome" può essere un preset di <code className="text-xs font-mono">chiedi_campo</code>
          con validatore "name" e domanda preimpostata.
        </p>
        <button
          onClick={openNew}
          className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg shrink-0"
        >
          <Plus className="w-4 h-4" />
          Crea preset
        </button>
      </div>

      {presets.presets.length === 0 && !isEditing && (
        <div className="text-center py-12 border-2 border-dashed border-zinc-200 rounded-lg">
          <Sparkles className="w-8 h-8 text-zinc-300 mx-auto mb-2" />
          <p className="text-sm text-zinc-500">Non ci sono ancora preset.</p>
          <button onClick={openNew} className="mt-2 text-sm text-emerald-600 hover:text-emerald-700 font-medium">
            Crea il primo
          </button>
        </div>
      )}

      {!isEditing && presets.presets.length > 0 && (
        <div className="space-y-2">
          {presets.presets.map((p) => {
            const baseMeta = catalog.modules.find((m) => m.key === p.base_module_key)
            const c = catColors(baseMeta?.category ?? p.category ?? 'other')
            return (
              <div
                key={p.id}
                onClick={() => openEdit(p)}
                className={`flex items-center gap-3 p-3 border border-zinc-200 rounded-lg hover:border-emerald-300 hover:bg-emerald-50/30 cursor-pointer transition-colors`}
              >
                <div className={`w-8 h-8 rounded-lg flex items-center justify-center ${c.bg} ${c.text}`}>
                  <Sparkles className="w-4 h-4" />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="font-medium text-zinc-900 text-sm">{p.label}</div>
                  <div className="text-[11px] text-zinc-500 mt-0.5">
                    <span className="font-mono">{p.key}</span>
                    {' · base: '}
                    <span className="font-mono text-zinc-600">{p.base_module_key}</span>
                  </div>
                </div>
                {p.description && <div className="text-xs text-zinc-500 max-w-sm truncate">{p.description}</div>}
              </div>
            )
          })}
        </div>
      )}

      {isEditing && (
        <PresetEditor
          preset={editing}
          builtins={catalog.builtins}
          baseMetaMap={Object.fromEntries(catalog.modules.map((m) => [m.key, m]))}
          onSaved={() => {
            setEditingId(null)
            refetch()
          }}
          onCancel={() => setEditingId(null)}
        />
      )}
    </div>
  )
}

/* ────────────────── Editor Preset (create + edit) ────────────────── */

function PresetEditor({
  preset,
  builtins,
  baseMetaMap,
  onSaved,
  onCancel,
}: {
  preset: Preset | null
  builtins: BuiltinRef[]
  baseMetaMap: Record<string, ModuleRow>
  onSaved: () => void
  onCancel: () => void
}) {
  const isEdit = preset !== null
  const [label, setLabel] = useState(preset?.label ?? '')
  const [description, setDescription] = useState(preset?.description ?? '')
  const [icon, setIcon] = useState(preset?.icon ?? '')
  const [category, setCategory] = useState(preset?.category ?? '')
  const [baseKey, setBaseKey] = useState(preset?.base_module_key ?? builtins[0]?.key ?? '')
  const [defaults, setDefaults] = useState<Record<string, unknown>>(preset?.config_defaults ?? {})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Se il base cambia e NON stiamo modificando un preset esistente, azzera i defaults
  // (lo schema è cambiato — i vecchi valori non sono più significativi).
  useEffect(() => {
    if (!isEdit) setDefaults({})
  }, [baseKey, isEdit])

  const baseMeta = baseMetaMap[baseKey]

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      const body = {
        base_module_key: baseKey,
        label: label.trim(),
        description: description.trim() || null,
        icon: icon.trim() || null,
        category: category.trim() || null,
        config_defaults: defaults,
      }
      if (isEdit) {
        await apiFetch(`/admin/flow/presets/${preset!.id}`, { method: 'PUT', body: JSON.stringify(body) })
      } else {
        await apiFetch('/admin/flow/presets', { method: 'POST', body: JSON.stringify(body) })
      }
      onSaved()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Errore salvataggio')
    } finally {
      setSaving(false)
    }
  }

  const del = async () => {
    if (!isEdit) return
    if (!confirm(`Eliminare il preset "${preset!.label}"?`)) return
    try {
      await apiFetch(`/admin/flow/presets/${preset!.id}`, { method: 'DELETE' })
      onSaved()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Errore eliminazione')
    }
  }

  return (
    <div className="bg-white border border-zinc-200 rounded-lg p-6 max-w-3xl">
      <div className="flex justify-between items-start mb-5">
        <div>
          <h2 className="text-lg font-semibold text-zinc-900">
            {isEdit ? 'Modifica preset' : 'Nuovo preset'}
          </h2>
          {isEdit && <p className="text-xs text-zinc-500 mt-1 font-mono">{preset!.key}</p>}
        </div>
        <button onClick={onCancel} className="text-zinc-400 hover:text-zinc-600">
          <X className="w-5 h-5" />
        </button>
      </div>

      {error && (
        <div className="mb-4 text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded p-3">{error}</div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Nome visibile<span className="text-rose-500 ml-0.5">*</span></div>
          <input
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder="Es. Chiedi nome utente"
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm"
          />
        </div>

        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Modulo base<span className="text-rose-500 ml-0.5">*</span></div>
          <select
            value={baseKey}
            onChange={(e) => setBaseKey(e.target.value)}
            disabled={isEdit}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm disabled:bg-zinc-50"
          >
            {builtins.map((b) => (
              <option key={b.key} value={b.key}>
                {b.category} · {b.label}
              </option>
            ))}
          </select>
          {isEdit && <div className="mt-1 text-[11px] text-zinc-500">Non modificabile dopo la creazione.</div>}
        </div>

        <div className="md:col-span-2">
          <div className="mb-1 text-xs font-medium text-zinc-700">Descrizione</div>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            placeholder="Aiuta altri a capire quando usare questo preset"
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm"
          />
        </div>

        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Icona (lucide)</div>
          <input
            value={icon}
            onChange={(e) => setIcon(e.target.value)}
            placeholder={baseMeta?.icon ?? 'sparkles'}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm font-mono"
          />
        </div>

        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Categoria (opz.)</div>
          <input
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            placeholder={baseMeta?.category ?? ''}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm font-mono"
          />
        </div>
      </div>

      {/* Config defaults dal base module */}
      {baseMeta && (
        <div className="border-t border-zinc-100 pt-5">
          <h3 className="text-sm font-semibold text-zinc-900 mb-1">Configurazione preimpostata</h3>
          <p className="text-xs text-zinc-500 mb-4">
            I campi del modulo base <span className="font-mono">{baseMeta.key}</span>. Quello che imposti qui
            diventa il default del preset; chi userà il preset in un flusso potrà comunque sovrascriverlo.
          </p>

          {Object.keys(baseMeta.config_schema).length === 0 && (
            <div className="text-xs text-zinc-500 italic">Il modulo base non ha campi configurabili.</div>
          )}

          {Object.entries(baseMeta.config_schema).map(([name, field]) => (
            <FieldEditor
              key={name}
              field={field}
              value={defaults[name] ?? field.default}
              onChange={(v) => setDefaults({ ...defaults, [name]: v })}
            />
          ))}
        </div>
      )}

      <div className="flex gap-2 pt-4 border-t border-zinc-100 mt-2">
        <button
          onClick={save}
          disabled={saving || !label.trim() || !baseKey}
          className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-lg disabled:opacity-50"
        >
          <Save className="w-4 h-4" />
          {saving ? 'Salvo...' : 'Salva preset'}
        </button>
        {isEdit && (
          <button
            onClick={del}
            className="flex items-center gap-1.5 text-rose-600 hover:bg-rose-50 text-sm font-medium px-3 py-2 rounded-lg"
          >
            <Trash2 className="w-4 h-4" />
            Elimina
          </button>
        )}
        <button
          onClick={onCancel}
          className="ml-auto text-sm text-zinc-600 hover:text-zinc-900 px-3 py-2"
        >
          Annulla
        </button>
      </div>
    </div>
  )
}

/* ────────────────── Tab Compositi ────────────────── */

type Composite = {
  id: number
  key: string
  label: string
  description: string | null
  icon: string | null
  category: string | null
  node_count: number
}

function CompositesTab() {
  const { data, refetch } = useApi<{ composites: Composite[] }>('/admin/flow/composites')
  const [creating, setCreating] = useState(false)

  if (!data) return <div className="text-sm text-zinc-500">Caricamento...</div>

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <p className="text-sm text-zinc-600 flex items-start gap-2">
          <Info className="w-4 h-4 text-zinc-400 mt-0.5 shrink-0" />
          I compositi sono sotto-grafi riusabili. Costruiscili una volta, usali in più punti del
          grafo principale. L'uscita del composito è segnata da moduli <code className="text-xs font-mono">composite_output</code>,
          che diventano le porte del modulo nel picker.
        </p>
        <button
          onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg shrink-0"
        >
          <Plus className="w-4 h-4" />
          Crea composito
        </button>
      </div>

      {data.composites.length === 0 && !creating && (
        <div className="text-center py-12 border-2 border-dashed border-zinc-200 rounded-lg">
          <Layers className="w-8 h-8 text-zinc-300 mx-auto mb-2" />
          <p className="text-sm text-zinc-500">Nessun composito ancora creato.</p>
          <button onClick={() => setCreating(true)} className="mt-2 text-sm text-emerald-600 hover:text-emerald-700 font-medium">
            Crea il primo
          </button>
        </div>
      )}

      {creating && (
        <CompositeCreateForm
          onCreated={(c) => {
            setCreating(false)
            refetch()
            // Naviga subito all'editor interno del composito appena creato.
            window.location.href = `/panel/flusso?composite=${c.id}`
          }}
          onCancel={() => setCreating(false)}
        />
      )}

      {!creating && data.composites.length > 0 && (
        <div className="space-y-2">
          {data.composites.map((c) => (
            <CompositeRow key={c.id} composite={c} onChanged={refetch} />
          ))}
        </div>
      )}
    </div>
  )
}

function CompositeRow({ composite, onChanged }: { composite: Composite; onChanged: () => void }) {
  const [editing, setEditing] = useState(false)

  const del = async () => {
    if (!confirm(`Eliminare il composito "${composite.label}"?`)) return
    try {
      await apiFetch(`/admin/flow/composites/${composite.id}`, { method: 'DELETE' })
      onChanged()
    } catch (e) {
      alert(e instanceof Error ? e.message : 'Errore eliminazione')
    }
  }

  if (editing) {
    return (
      <CompositeEditForm
        composite={composite}
        onSaved={() => { setEditing(false); onChanged() }}
        onCancel={() => setEditing(false)}
      />
    )
  }

  return (
    <div className="flex items-center gap-3 p-3 border border-zinc-200 rounded-lg hover:border-emerald-300 transition-colors group">
      <div className="w-8 h-8 rounded-lg flex items-center justify-center bg-violet-50 text-violet-700">
        <Layers className="w-4 h-4" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="font-medium text-zinc-900 text-sm">{composite.label}</div>
        <div className="text-[11px] text-zinc-500 mt-0.5">
          <span className="font-mono">{composite.key}</span>
          {' · '}
          {composite.node_count} nodi
          {composite.category && <> · categoria <span className="font-mono">{composite.category}</span></>}
        </div>
      </div>
      {composite.description && <div className="text-xs text-zinc-500 max-w-sm truncate">{composite.description}</div>}
      <div className="flex items-center gap-1 opacity-70 group-hover:opacity-100 transition-opacity">
        <Link
          to={`/flusso?composite=${composite.id}`}
          className="flex items-center gap-1 text-xs text-emerald-600 hover:bg-emerald-50 font-medium px-2 py-1 rounded"
        >
          Modifica sotto-grafo <ArrowRight className="w-3 h-3" />
        </Link>
        <button onClick={() => setEditing(true)} className="p-1 text-zinc-400 hover:text-zinc-600 rounded" title="Modifica metadati">
          <Info className="w-4 h-4" />
        </button>
        <button onClick={del} className="p-1 text-zinc-400 hover:text-rose-500 rounded" title="Elimina">
          <Trash2 className="w-4 h-4" />
        </button>
      </div>
    </div>
  )
}

function CompositeCreateForm({ onCreated, onCancel }: { onCreated: (c: Composite) => void; onCancel: () => void }) {
  const [label, setLabel] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState('custom')
  const [icon, setIcon] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async () => {
    if (!label.trim()) return
    setSaving(true)
    try {
      const created = await apiFetch<Composite>('/admin/flow/composites', {
        method: 'POST',
        body: JSON.stringify({
          label: label.trim(),
          description: description.trim() || null,
          category: category.trim() || 'custom',
          icon: icon.trim() || null,
        }),
      })
      onCreated(created)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="bg-white border border-zinc-200 rounded-lg p-6 max-w-2xl">
      <div className="flex justify-between items-start mb-4">
        <div>
          <h2 className="text-lg font-semibold text-zinc-900">Nuovo composito</h2>
          <p className="text-xs text-zinc-500 mt-1">Dopo la creazione ti porto subito all'editor per costruire il sotto-grafo.</p>
        </div>
        <button onClick={onCancel} className="text-zinc-400 hover:text-zinc-600"><X className="w-5 h-5" /></button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Nome<span className="text-rose-500 ml-0.5">*</span></div>
          <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Es. Chiedi nome e valida" className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm" />
        </div>
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Categoria</div>
          <input value={category} onChange={(e) => setCategory(e.target.value)} placeholder="custom" className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm font-mono" />
        </div>
        <div className="md:col-span-2">
          <div className="mb-1 text-xs font-medium text-zinc-700">Descrizione</div>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm" />
        </div>
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Icona (lucide)</div>
          <input value={icon} onChange={(e) => setIcon(e.target.value)} placeholder="layers" className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm font-mono" />
        </div>
      </div>

      <div className="flex gap-2">
        <button onClick={submit} disabled={saving || !label.trim()} className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-lg disabled:opacity-50">
          <Save className="w-4 h-4" />
          {saving ? 'Creo...' : 'Crea e modifica'}
        </button>
        <button onClick={onCancel} className="text-sm text-zinc-600 hover:text-zinc-900 px-3 py-2">Annulla</button>
      </div>
    </div>
  )
}

function CompositeEditForm({ composite, onSaved, onCancel }: { composite: Composite; onSaved: () => void; onCancel: () => void }) {
  const [label, setLabel] = useState(composite.label)
  const [description, setDescription] = useState(composite.description ?? '')
  const [category, setCategory] = useState(composite.category ?? '')
  const [icon, setIcon] = useState(composite.icon ?? '')
  const [saving, setSaving] = useState(false)

  const save = async () => {
    setSaving(true)
    try {
      await apiFetch(`/admin/flow/composites/${composite.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          label: label.trim(),
          description: description.trim() || null,
          category: category.trim() || null,
          icon: icon.trim() || null,
        }),
      })
      onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="bg-white border border-emerald-300 rounded-lg p-4 shadow-sm">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-semibold text-zinc-900">Metadati composito <span className="font-mono text-xs text-zinc-500 ml-2">{composite.key}</span></h3>
        <button onClick={onCancel} className="text-zinc-400 hover:text-zinc-600"><X className="w-4 h-4" /></button>
      </div>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Nome</div>
          <input value={label} onChange={(e) => setLabel(e.target.value)} className="w-full rounded border border-zinc-300 px-2 py-1 text-sm" />
        </div>
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Categoria</div>
          <input value={category} onChange={(e) => setCategory(e.target.value)} className="w-full rounded border border-zinc-300 px-2 py-1 text-sm font-mono" />
        </div>
        <div>
          <div className="mb-1 text-xs font-medium text-zinc-700">Icona</div>
          <input value={icon} onChange={(e) => setIcon(e.target.value)} className="w-full rounded border border-zinc-300 px-2 py-1 text-sm font-mono" />
        </div>
        <div className="col-span-2 md:col-span-4">
          <div className="mb-1 text-xs font-medium text-zinc-700">Descrizione</div>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} className="w-full rounded border border-zinc-300 px-2 py-1 text-sm" />
        </div>
      </div>
      <div className="flex gap-2">
        <button onClick={save} disabled={saving} className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-medium px-3 py-1.5 rounded disabled:opacity-50">
          <Save className="w-3.5 h-3.5" />
          {saving ? 'Salvo...' : 'Salva'}
        </button>
        <button onClick={onCancel} className="text-xs text-zinc-600 hover:text-zinc-900 px-3 py-1.5">Annulla</button>
      </div>
    </div>
  )
}
