import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ReactFlow,
  Background,
  Controls,
  MiniMap,
  Handle,
  Position,
  addEdge,
  useNodesState,
  useEdgesState,
  type Node,
  type Edge,
  type Connection,
  type NodeProps,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { apiFetch, useApi } from '@/hooks/use-api'
import { Plus, X, Save, Trash2, Zap, Play } from 'lucide-react'

/* ────────────────────────────── Tipi ────────────────────────────── */

type ConfigField = {
  type: string
  label: string
  required?: boolean
  default?: unknown
  help?: string
  options?: { value: string; label: string }[]
  max?: number
}

type ModuleMeta = {
  key: string
  label: string
  category: string
  description: string
  config_schema: Record<string, ConfigField>
  icon: string
}

type GraphNode = {
  id: number
  module_key: string
  module_label: string
  category: string
  icon: string
  label: string | null
  config: Record<string, unknown>
  position: { x: number; y: number }
  is_entry: boolean
  entry_trigger: string | null
  outputs: Record<string, string>
}

type GraphEdge = {
  id: number
  from_node_id: number
  from_port: string
  to_node_id: number
  to_port: string
}

type ModulesResponse = { grouped: Record<string, ModuleMeta[]> }
type GraphResponse = { nodes: GraphNode[]; edges: GraphEdge[] }

/* ───────────────────── Colori per categoria ───────────────────── */

const CATEGORY_COLORS: Record<string, string> = {
  trigger: 'bg-emerald-500',
  logica: 'bg-violet-500',
  invio: 'bg-sky-500',
  attesa: 'bg-amber-500',
  dati: 'bg-slate-500',
  azione: 'bg-rose-500',
  ai: 'bg-fuchsia-500',
}

function catColor(cat: string): string {
  return CATEGORY_COLORS[cat] ?? 'bg-zinc-500'
}

/* ───────────────── Custom React Flow node card ───────────────── */

function ModuleCard({ data }: NodeProps) {
  const n = data as unknown as GraphNode
  const outputs = Object.entries(n.outputs)

  return (
    <div className="bg-white rounded-xl border border-zinc-200 shadow-sm min-w-[220px] max-w-[280px] overflow-hidden">
      {/* Input handle */}
      <Handle
        type="target"
        position={Position.Top}
        id="in"
        className="!bg-zinc-400 !w-3 !h-3 !border-2 !border-white"
      />

      {/* Header */}
      <div className={`${catColor(n.category)} px-3 py-2 text-white text-xs font-medium flex items-center gap-2`}>
        {n.is_entry && <Zap className="w-3 h-3" />}
        <span className="truncate">{n.module_label}</span>
      </div>

      {/* Body */}
      <div className="p-3">
        <div className="text-sm font-medium text-zinc-900 truncate">
          {n.label || n.module_label}
        </div>
        {n.entry_trigger && (
          <div className="mt-1 text-[10px] text-zinc-500 font-mono">
            ↯ {n.entry_trigger}
          </div>
        )}

        {/* Output ports */}
        {outputs.length > 0 && (
          <div className="mt-3 space-y-1">
            {outputs.map(([port, label]) => (
              <div key={port} className="relative text-xs text-zinc-600 flex justify-between items-center pr-3">
                <span className="truncate">{label}</span>
                <Handle
                  type="source"
                  position={Position.Right}
                  id={port}
                  style={{ right: -6, top: 'auto', position: 'absolute' }}
                  className="!bg-zinc-400 !w-3 !h-3 !border-2 !border-white"
                />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

const nodeTypes = { module: ModuleCard }

/* ──────────────────────── Config field editor ──────────────────────── */

function FieldEditor({
  field,
  value,
  onChange,
}: {
  field: ConfigField
  value: unknown
  onChange: (v: unknown) => void
}) {
  const label = (
    <div className="mb-1 text-xs font-medium text-zinc-700">
      {field.label}
      {field.required && <span className="text-rose-500 ml-0.5">*</span>}
    </div>
  )
  const help = field.help && <div className="mt-1 text-[11px] text-zinc-500">{field.help}</div>

  switch (field.type) {
    case 'text':
      return (
        <div className="mb-3">
          {label}
          <textarea
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
            rows={3}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
          />
          {help}
        </div>
      )

    case 'int':
      return (
        <div className="mb-3">
          {label}
          <input
            type="number"
            value={(value as number) ?? ''}
            onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
          />
          {help}
        </div>
      )

    case 'bool':
      return (
        <div className="mb-3">
          <label className="flex items-center gap-2 text-sm text-zinc-700">
            <input
              type="checkbox"
              checked={Boolean(value)}
              onChange={(e) => onChange(e.target.checked)}
              className="rounded"
            />
            {field.label}
          </label>
          {help}
        </div>
      )

    case 'select':
      return (
        <div className="mb-3">
          {label}
          <select
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
          >
            {field.options?.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
          {help}
        </div>
      )

    case 'button_list': {
      const list = Array.isArray(value) ? (value as { label: string }[]) : []
      const max = field.max ?? 3
      return (
        <div className="mb-3">
          {label}
          <div className="space-y-1">
            {list.map((btn, i) => (
              <div key={i} className="flex gap-1">
                <input
                  value={btn.label ?? ''}
                  onChange={(e) => {
                    const next = [...list]
                    next[i] = { ...btn, label: e.target.value }
                    onChange(next)
                  }}
                  maxLength={20}
                  placeholder={`Bottone ${i + 1}`}
                  className="flex-1 rounded border border-zinc-300 px-2 py-1 text-sm"
                />
                <button
                  onClick={() => onChange(list.filter((_, j) => j !== i))}
                  className="px-2 text-zinc-400 hover:text-rose-500"
                  type="button"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            ))}
            {list.length < max && (
              <button
                onClick={() => onChange([...list, { label: '' }])}
                type="button"
                className="w-full text-xs text-zinc-600 border border-dashed border-zinc-300 rounded py-1 hover:bg-zinc-50"
              >
                + Aggiungi bottone
              </button>
            )}
          </div>
          {help}
        </div>
      )
    }

    case 'key_value': {
      const list = Array.isArray(value) ? (value as { key: string; value: unknown }[]) : []
      return (
        <div className="mb-3">
          {label}
          <div className="space-y-1">
            {list.map((row, i) => (
              <div key={i} className="flex gap-1">
                <input
                  value={row.key ?? ''}
                  onChange={(e) => {
                    const next = [...list]
                    next[i] = { ...row, key: e.target.value }
                    onChange(next)
                  }}
                  placeholder="chiave"
                  className="flex-1 rounded border border-zinc-300 px-2 py-1 text-xs font-mono"
                />
                <input
                  value={String(row.value ?? '')}
                  onChange={(e) => {
                    const next = [...list]
                    next[i] = { ...row, value: e.target.value }
                    onChange(next)
                  }}
                  placeholder="valore"
                  className="flex-1 rounded border border-zinc-300 px-2 py-1 text-xs"
                />
                <button
                  onClick={() => onChange(list.filter((_, j) => j !== i))}
                  className="px-2 text-zinc-400 hover:text-rose-500"
                  type="button"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            ))}
            <button
              onClick={() => onChange([...list, { key: '', value: '' }])}
              type="button"
              className="w-full text-xs text-zinc-600 border border-dashed border-zinc-300 rounded py-1 hover:bg-zinc-50"
            >
              + Aggiungi riga
            </button>
          </div>
          {help}
        </div>
      )
    }

    case 'string_list': {
      const list = Array.isArray(value) ? (value as string[]) : []
      return (
        <div className="mb-3">
          {label}
          <div className="space-y-1">
            {list.map((s, i) => (
              <div key={i} className="flex gap-1">
                <input
                  value={s}
                  onChange={(e) => {
                    const next = [...list]
                    next[i] = e.target.value
                    onChange(next)
                  }}
                  className="flex-1 rounded border border-zinc-300 px-2 py-1 text-sm"
                />
                <button
                  onClick={() => onChange(list.filter((_, j) => j !== i))}
                  className="px-2 text-zinc-400 hover:text-rose-500"
                  type="button"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            ))}
            <button
              onClick={() => onChange([...list, ''])}
              type="button"
              className="w-full text-xs text-zinc-600 border border-dashed border-zinc-300 rounded py-1 hover:bg-zinc-50"
            >
              + Aggiungi voce
            </button>
          </div>
          {help}
        </div>
      )
    }

    default:
      return (
        <div className="mb-3">
          {label}
          <input
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
            className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
          />
          {help}
        </div>
      )
  }
}

/* ──────────────────────── Pagina Flusso ──────────────────────── */

export function Flusso() {
  const { data: modulesData } = useApi<ModulesResponse>('/admin/flow/modules')
  const { data: graphData, refetch: refetchGraph } = useApi<GraphResponse>('/admin/flow/graph')

  const [nodes, setNodes, onNodesChange] = useNodesState<Node>([])
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [showPicker, setShowPicker] = useState(false)
  const [saving, setSaving] = useState(false)

  /* Sincronizza stato React Flow con graphData quando arriva dall'API */
  useEffect(() => {
    if (!graphData) return
    setNodes(
      graphData.nodes.map<Node>((n) => ({
        id: String(n.id),
        type: 'module',
        position: { x: n.position.x ?? 0, y: n.position.y ?? 0 },
        data: n as unknown as Record<string, unknown>,
      })),
    )
    setEdges(
      graphData.edges.map<Edge>((e) => ({
        id: String(e.id),
        source: String(e.from_node_id),
        sourceHandle: e.from_port,
        target: String(e.to_node_id),
        targetHandle: e.to_port,
        animated: false,
        style: { stroke: '#a1a1aa', strokeWidth: 2 },
      })),
    )
  }, [graphData, setNodes, setEdges])

  const selectedGraphNode: GraphNode | null = useMemo(() => {
    if (!selectedId || !graphData) return null
    return graphData.nodes.find((n) => n.id === selectedId) ?? null
  }, [selectedId, graphData])

  const selectedModuleMeta: ModuleMeta | null = useMemo(() => {
    if (!selectedGraphNode || !modulesData) return null
    const all = Object.values(modulesData.grouped).flat()
    return all.find((m) => m.key === selectedGraphNode.module_key) ?? null
  }, [selectedGraphNode, modulesData])

  /* Handler: drag from port → drop on port → crea edge sul server */
  const onConnect = useCallback(
    async (conn: Connection) => {
      if (!conn.source || !conn.target) return
      setEdges((eds) => addEdge({ ...conn, animated: false, style: { stroke: '#a1a1aa', strokeWidth: 2 } }, eds))
      try {
        await apiFetch('/admin/flow/edges', {
          method: 'POST',
          body: JSON.stringify({
            from_node_id: Number(conn.source),
            from_port: conn.sourceHandle ?? 'out',
            to_node_id: Number(conn.target),
            to_port: conn.targetHandle ?? 'in',
          }),
        })
        refetchGraph()
      } catch (e) {
        console.error('createEdge failed', e)
      }
    },
    [setEdges, refetchGraph],
  )

  /* Handler: click nodo → apre side panel */
  const onNodeClick = useCallback((_: unknown, node: Node) => {
    setSelectedId(Number(node.id))
    setShowPicker(false)
  }, [])

  /* Crea nuovo nodo da module picker */
  const addNode = async (moduleKey: string) => {
    try {
      const created = await apiFetch<GraphNode>('/admin/flow/nodes', {
        method: 'POST',
        body: JSON.stringify({
          module_key: moduleKey,
          position: { x: 100, y: 100 },
          config: {},
        }),
      })
      refetchGraph()
      setSelectedId(created.id)
      setShowPicker(false)
    } catch (e) {
      console.error('createNode failed', e)
    }
  }

  /* Salva modifiche al nodo selezionato */
  const saveSelected = async (patch: Partial<GraphNode>) => {
    if (!selectedId) return
    setSaving(true)
    try {
      await apiFetch(`/admin/flow/nodes/${selectedId}`, {
        method: 'PUT',
        body: JSON.stringify(patch),
      })
      refetchGraph()
    } finally {
      setSaving(false)
    }
  }

  const deleteSelected = async () => {
    if (!selectedId) return
    if (!confirm('Eliminare questo nodo? Gli archi collegati verranno rimossi.')) return
    await apiFetch(`/admin/flow/nodes/${selectedId}`, { method: 'DELETE' })
    setSelectedId(null)
    refetchGraph()
  }

  return (
    <div className="h-[calc(100vh-4rem)] flex">
      {/* ── Canvas ── */}
      <div className="flex-1 relative">
        <ReactFlow
          nodes={nodes}
          edges={edges}
          onNodesChange={onNodesChange}
          onEdgesChange={onEdgesChange}
          onConnect={onConnect}
          onNodeClick={onNodeClick}
          nodeTypes={nodeTypes}
          fitView
        >
          <Background color="#e4e4e7" gap={24} />
          <Controls className="!bottom-4 !left-4" />
          <MiniMap pannable zoomable className="!bottom-4 !right-4" />
        </ReactFlow>

        {/* Toolbar in alto */}
        <div className="absolute top-4 left-4 z-10 flex gap-2">
          <button
            onClick={() => { setShowPicker(!showPicker); setSelectedId(null) }}
            className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg shadow-sm"
          >
            <Plus className="w-4 h-4" />
            Aggiungi modulo
          </button>
          <div className="bg-white border border-zinc-200 rounded-lg px-3 py-2 text-xs text-zinc-600 shadow-sm flex items-center gap-1.5">
            <Play className="w-3 h-3" />
            {nodes.length} nodi · {edges.length} archi
          </div>
        </div>
      </div>

      {/* ── Right drawer: module picker OPPURE node editor ── */}
      {(showPicker || selectedGraphNode) && (
        <div className="w-96 border-l border-zinc-200 bg-white overflow-y-auto">
          {showPicker && modulesData && (
            <div className="p-4">
              <div className="flex justify-between items-center mb-4">
                <h2 className="font-semibold text-zinc-900">Moduli disponibili</h2>
                <button onClick={() => setShowPicker(false)} className="text-zinc-400 hover:text-zinc-600">
                  <X className="w-5 h-5" />
                </button>
              </div>

              {Object.entries(modulesData.grouped).map(([cat, mods]) => (
                <div key={cat} className="mb-4">
                  <div className="flex items-center gap-2 mb-2">
                    <span className={`w-2 h-2 rounded-full ${catColor(cat)}`} />
                    <span className="text-xs font-semibold uppercase text-zinc-500 tracking-wide">{cat}</span>
                  </div>
                  <div className="space-y-1">
                    {mods.map((m) => (
                      <button
                        key={m.key}
                        onClick={() => addNode(m.key)}
                        className="w-full text-left p-2 rounded hover:bg-zinc-50 border border-transparent hover:border-zinc-200 transition-colors"
                      >
                        <div className="text-sm font-medium text-zinc-900">{m.label}</div>
                        <div className="text-[11px] text-zinc-500 leading-snug mt-0.5">{m.description}</div>
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}

          {!showPicker && selectedGraphNode && selectedModuleMeta && (
            <NodeEditor
              key={selectedGraphNode.id}
              node={selectedGraphNode}
              meta={selectedModuleMeta}
              onSave={saveSelected}
              onDelete={deleteSelected}
              onClose={() => setSelectedId(null)}
              saving={saving}
            />
          )}
        </div>
      )}
    </div>
  )
}

/* ─────────────────── Side panel: editor nodo ─────────────────── */

function NodeEditor({
  node,
  meta,
  onSave,
  onDelete,
  onClose,
  saving,
}: {
  node: GraphNode
  meta: ModuleMeta
  onSave: (patch: Partial<GraphNode>) => void
  onDelete: () => void
  onClose: () => void
  saving: boolean
}) {
  const [label, setLabel] = useState(node.label ?? '')
  const [config, setConfig] = useState<Record<string, unknown>>(node.config ?? {})
  const [isEntry, setIsEntry] = useState(node.is_entry)
  const [entryTrigger, setEntryTrigger] = useState(node.entry_trigger ?? '')

  useEffect(() => {
    setLabel(node.label ?? '')
    setConfig(node.config ?? {})
    setIsEntry(node.is_entry)
    setEntryTrigger(node.entry_trigger ?? '')
  }, [node])

  const save = () => {
    onSave({
      label: label || null,
      config,
      is_entry: isEntry,
      entry_trigger: isEntry ? entryTrigger || null : null,
    } as Partial<GraphNode>)
  }

  return (
    <div className="p-4">
      <div className="flex justify-between items-start mb-1">
        <div>
          <div className={`inline-block text-[10px] font-semibold uppercase tracking-wide text-white px-2 py-0.5 rounded ${catColor(meta.category)}`}>
            {meta.category}
          </div>
          <h2 className="mt-1 font-semibold text-zinc-900">{meta.label}</h2>
        </div>
        <button onClick={onClose} className="text-zinc-400 hover:text-zinc-600">
          <X className="w-5 h-5" />
        </button>
      </div>
      <p className="text-xs text-zinc-500 mb-4">{meta.description}</p>

      {/* Label custom */}
      <div className="mb-3">
        <div className="mb-1 text-xs font-medium text-zinc-700">Titolo (per l'editor)</div>
        <input
          value={label}
          onChange={(e) => setLabel(e.target.value)}
          placeholder={meta.label}
          className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm"
        />
      </div>

      {/* Trigger toggle */}
      <div className="mb-4 p-3 bg-zinc-50 rounded border border-zinc-200">
        <label className="flex items-center gap-2 text-sm font-medium text-zinc-700">
          <input type="checkbox" checked={isEntry} onChange={(e) => setIsEntry(e.target.checked)} />
          Punto di ingresso (trigger)
        </label>
        {isEntry && (
          <div className="mt-2">
            <input
              value={entryTrigger}
              onChange={(e) => setEntryTrigger(e.target.value)}
              placeholder="first_message  oppure  keyword:menu"
              className="w-full rounded border border-zinc-300 px-2 py-1.5 text-xs font-mono"
            />
            <div className="mt-1 text-[11px] text-zinc-500">
              Formati: <code>first_message</code>, <code>keyword:prenotazioni</code>
            </div>
          </div>
        )}
      </div>

      {/* Config schema fields */}
      <div className="mb-4">
        <div className="text-xs font-semibold uppercase text-zinc-500 tracking-wide mb-2">Configurazione</div>
        {Object.keys(meta.config_schema).length === 0 && (
          <div className="text-xs text-zinc-500 italic">Questo modulo non ha opzioni.</div>
        )}
        {Object.entries(meta.config_schema).map(([name, field]) => (
          <FieldEditor
            key={name}
            field={field}
            value={config[name] ?? field.default}
            onChange={(v) => setConfig({ ...config, [name]: v })}
          />
        ))}
      </div>

      {/* Azioni */}
      <div className="flex gap-2 sticky bottom-0 bg-white pt-3 border-t border-zinc-100">
        <button
          onClick={save}
          disabled={saving}
          className="flex-1 flex items-center justify-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg disabled:opacity-50"
        >
          <Save className="w-4 h-4" />
          {saving ? 'Salvo...' : 'Salva'}
        </button>
        <button
          onClick={onDelete}
          className="px-3 py-2 text-zinc-400 hover:text-rose-500 border border-zinc-200 rounded-lg"
        >
          <Trash2 className="w-4 h-4" />
        </button>
      </div>
    </div>
  )
}
