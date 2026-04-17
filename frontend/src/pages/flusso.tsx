import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { apiFetch, useApi } from '@/hooks/use-api'
import { FieldEditor, type ConfigField } from '@/components/flow/field-editor'
import {
  Plus, X, Save, Trash2, Zap, Clock, Database, Play,
  MessageSquare, GitBranch, Sparkles, Layers, ArrowLeft,
  ChevronDown, ChevronRight, CornerDownRight, ArrowRight,
} from 'lucide-react'

/* ────────────────── Tipi ────────────────── */

type ModuleMeta = {
  key: string; label: string; category: string; description: string
  config_schema: Record<string, ConfigField>; icon: string
}

type GraphNode = {
  id: number; module_key: string; module_label: string; category: string
  icon: string; label: string | null; config: Record<string, unknown>
  position: { x: number; y: number }; is_entry: boolean
  entry_trigger: string | null; outputs: Record<string, string>
}

type GraphEdge = {
  id: number; from_node_id: number; from_port: string
  to_node_id: number; to_port: string
}

type CompositeInfo = { id: number; key: string; label: string }
type ModulesResponse = { grouped: Record<string, ModuleMeta[]> }
type GraphResponse = { nodes: GraphNode[]; edges: GraphEdge[]; composite?: CompositeInfo }

/* ────────── Struttura ad albero (calcolata dal grafo) ────────── */

type TreeNode = {
  node: GraphNode
  kind: 'step' | 'branch' | 'goto'
  gotoLabel?: string
  branches?: { port: string; label: string; children: TreeNode[] }[]
  next?: TreeNode
}

function buildTree(
  startId: number,
  nodesMap: Map<number, GraphNode>,
  edgesBySource: Map<string, GraphEdge>,
  visited: Set<number>,
): TreeNode | null {
  const node = nodesMap.get(startId)
  if (!node) return null

  if (visited.has(startId)) {
    return { node, kind: 'goto', gotoLabel: node.label || node.module_label }
  }
  visited.add(startId)

  const outPorts = Object.keys(node.outputs)
  const liveEdges: { port: string; label: string; targetId: number }[] = []

  for (const port of outPorts) {
    const edge = edgesBySource.get(`${startId}:${port}`)
    if (edge) {
      liveEdges.push({
        port,
        label: node.outputs[port] ?? port,
        targetId: edge.to_node_id,
      })
    }
  }

  // Self-loop filtering (e.g., fallback → same node = re-prompt)
  const nonSelf = liveEdges.filter(e => e.targetId !== startId)

  if (nonSelf.length === 0) {
    return { node, kind: 'step' }
  }

  if (nonSelf.length === 1) {
    const next = buildTree(nonSelf[0].targetId, nodesMap, edgesBySource, visited)
    return { node, kind: 'step', next: next ?? undefined }
  }

  // Branch point
  const branches = nonSelf.map(e => ({
    port: e.port,
    label: e.label,
    children: (() => {
      const child = buildTree(e.targetId, nodesMap, edgesBySource, new Set(visited))
      return child ? flattenChain(child) : []
    })(),
  }))

  return { node, kind: 'branch', branches }
}

/** Converte una catena next→next in una lista piatta per rendering lineare */
function flattenChain(tree: TreeNode): TreeNode[] {
  const out: TreeNode[] = []
  let cur: TreeNode | undefined = tree
  while (cur) {
    out.push(cur)
    if (cur.kind === 'branch' || cur.kind === 'goto') break
    cur = cur.next
  }
  return out
}

function buildForest(
  nodes: GraphNode[],
  edges: GraphEdge[],
): TreeNode[][] {
  const nodesMap = new Map(nodes.map(n => [n.id, n]))
  const edgesBySource = new Map<string, GraphEdge>()
  for (const e of edges) {
    edgesBySource.set(`${e.from_node_id}:${e.from_port}`, e)
  }

  const entryNodes = nodes
    .filter(n => n.is_entry)
    .sort((a, b) => {
      // first_message trigger first, keyword triggers after
      const aw = a.entry_trigger?.startsWith('keyword') ? 1 : 0
      const bw = b.entry_trigger?.startsWith('keyword') ? 1 : 0
      return aw - bw
    })

  if (entryNodes.length === 0 && nodes.length > 0) {
    // Composite senza entry? Prendi il primo nodo
    entryNodes.push(nodes[0])
  }

  const globalVisited = new Set<number>()
  return entryNodes.map(entry => {
    const tree = buildTree(entry.id, nodesMap, edgesBySource, globalVisited)
    return tree ? flattenChain(tree) : []
  })
}

/* ────────── Stili per categoria ────────── */

const CAT_STYLES: Record<string, { border: string; bg: string; icon: React.ReactNode; verb: string }> = {
  trigger:   { border: 'border-l-emerald-500', bg: 'bg-emerald-50',  icon: <Zap className="w-4 h-4 text-emerald-600" />,   verb: 'Quando' },
  logica:    { border: 'border-l-violet-500',  bg: 'bg-violet-50',   icon: <GitBranch className="w-4 h-4 text-violet-600" />, verb: 'Se' },
  invio:     { border: 'border-l-sky-500',     bg: 'bg-sky-50',      icon: <MessageSquare className="w-4 h-4 text-sky-600" />, verb: 'Messaggio' },
  attesa:    { border: 'border-l-amber-500',   bg: 'bg-amber-50',    icon: <Clock className="w-4 h-4 text-amber-600" />,    verb: 'Aspetta' },
  dati:      { border: 'border-l-slate-500',   bg: 'bg-slate-50',    icon: <Database className="w-4 h-4 text-slate-600" />,  verb: 'Dati' },
  azione:    { border: 'border-l-rose-500',    bg: 'bg-rose-50',     icon: <Play className="w-4 h-4 text-rose-600" />,      verb: 'Azione' },
  ai:        { border: 'border-l-fuchsia-500', bg: 'bg-fuchsia-50',  icon: <Sparkles className="w-4 h-4 text-fuchsia-600" />, verb: 'AI' },
  composito: { border: 'border-l-violet-500',  bg: 'bg-violet-50',   icon: <Layers className="w-4 h-4 text-violet-600" />,  verb: 'Composito' },
  custom:    { border: 'border-l-violet-500',  bg: 'bg-violet-50',   icon: <Layers className="w-4 h-4 text-violet-600" />,  verb: 'Composito' },
}
const catStyle = (c: string) => CAT_STYLES[c] ?? CAT_STYLES.dati

/* ────────── Pagina principale ────────── */

export function Flusso() {
  const [searchParams] = useSearchParams()
  const compositeId = searchParams.get('composite')
  const isComposite = compositeId !== null
  const apiBase = isComposite ? `/admin/flow/composites/${compositeId}` : '/admin/flow'

  const { data: modulesData } = useApi<ModulesResponse>('/admin/flow/modules')
  const { data: graphData, refetch } = useApi<GraphResponse>(`${apiBase}/graph`)

  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [showPicker, setShowPicker] = useState(false)
  const [saving, setSaving] = useState(false)

  // Punto di inserimento: la "+" cliccata passa l'info per creare un nodo IN MEZZO a un arco
  const [insertPoint, setInsertPoint] = useState<{
    fromNodeId: number; fromPort: string; toNodeId: number
  } | null>(null)

  const forest = useMemo(() => {
    if (!graphData) return []
    return buildForest(graphData.nodes, graphData.edges)
  }, [graphData])

  const selectedNode = useMemo(
    () => graphData?.nodes.find(n => n.id === selectedId) ?? null,
    [selectedId, graphData],
  )
  const selectedMeta = useMemo(() => {
    if (!selectedNode || !modulesData) return null
    return Object.values(modulesData.grouped).flat().find(m => m.key === selectedNode.module_key) ?? null
  }, [selectedNode, modulesData])

  const addNode = async (moduleKey: string) => {
    try {
      const node = await apiFetch<GraphNode>(`${apiBase}/nodes`, {
        method: 'POST',
        body: JSON.stringify({ module_key: moduleKey, position: { x: 0, y: 0 }, config: {} }),
      })

      // Se c'è un insertPoint, rewire: cancella vecchio edge, crea 2 nuovi
      if (insertPoint) {
        const oldEdge = graphData?.edges.find(
          e => e.from_node_id === insertPoint.fromNodeId
            && e.from_port === insertPoint.fromPort
            && e.to_node_id === insertPoint.toNodeId
        )
        if (oldEdge) {
          await apiFetch(`${apiBase}/edges/${oldEdge.id}`, { method: 'DELETE' })
        }
        await apiFetch(`${apiBase}/edges`, {
          method: 'POST',
          body: JSON.stringify({
            from_node_id: insertPoint.fromNodeId,
            from_port: insertPoint.fromPort,
            to_node_id: node.id,
          }),
        })
        await apiFetch(`${apiBase}/edges`, {
          method: 'POST',
          body: JSON.stringify({
            from_node_id: node.id,
            from_port: 'out',
            to_node_id: insertPoint.toNodeId,
          }),
        })
        setInsertPoint(null)
      }

      refetch()
      setSelectedId(node.id)
      setShowPicker(false)
    } catch (e) {
      console.error('addNode failed', e)
    }
  }

  const saveSelected = async (patch: Partial<GraphNode>) => {
    if (!selectedId) return
    setSaving(true)
    try {
      await apiFetch(`${apiBase}/nodes/${selectedId}`, {
        method: 'PUT',
        body: JSON.stringify(patch),
      })
      refetch()
    } finally { setSaving(false) }
  }

  const deleteSelected = async () => {
    if (!selectedId || !confirm('Eliminare questo step?')) return
    await apiFetch(`${apiBase}/nodes/${selectedId}`, { method: 'DELETE' })
    setSelectedId(null)
    refetch()
  }

  const onInsert = (fromNodeId: number, fromPort: string, toNodeId: number) => {
    setInsertPoint({ fromNodeId, fromPort, toNodeId })
    setShowPicker(true)
    setSelectedId(null)
  }

  if (!graphData || !modulesData) {
    return <div className="p-8 text-zinc-500">Caricamento...</div>
  }

  return (
    <div className="flex h-[calc(100vh-4rem)]">
      {/* ── Main: conversation view ── */}
      <div className="flex-1 overflow-y-auto bg-zinc-50/50">
        {/* Header */}
        <div className="sticky top-0 z-10 bg-white/90 backdrop-blur border-b border-zinc-200 px-6 py-3 flex items-center gap-3">
          {isComposite && (
            <Link to="/moduli" className="text-zinc-400 hover:text-zinc-700">
              <ArrowLeft className="w-5 h-5" />
            </Link>
          )}
          <div className="flex-1">
            <h1 className="text-lg font-semibold text-zinc-900">
              {isComposite && graphData.composite
                ? `Composito: ${graphData.composite.label}`
                : 'Flusso Conversazionale'}
            </h1>
            <p className="text-xs text-zinc-500">
              {graphData.nodes.length} step · {graphData.edges.length} connessioni
            </p>
          </div>
          <button
            onClick={() => { setShowPicker(!showPicker); setSelectedId(null); setInsertPoint(null) }}
            className="flex items-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg"
          >
            <Plus className="w-4 h-4" />
            Aggiungi step
          </button>
        </div>

        {/* Conversation flows */}
        <div className="max-w-2xl mx-auto py-8 px-4">
          {forest.length === 0 && (
            <div className="text-center py-16 border-2 border-dashed border-zinc-200 rounded-xl">
              <MessageSquare className="w-10 h-10 text-zinc-300 mx-auto mb-3" />
              <p className="text-zinc-500">Nessun flusso. Aggiungi un trigger per iniziare.</p>
            </div>
          )}

          {forest.map((chain, fi) => (
            <div key={fi} className="mb-12">
              {fi > 0 && <div className="border-t border-zinc-200 my-8" />}
              <StepChain
                steps={chain}
                edges={graphData.edges}
                selectedId={selectedId}
                onSelect={setSelectedId}
                onInsert={onInsert}
              />
            </div>
          ))}
        </div>
      </div>

      {/* ── Right drawer ── */}
      {(showPicker || selectedNode) && (
        <div className="w-96 border-l border-zinc-200 bg-white overflow-y-auto shrink-0">
          {showPicker && (
            <div className="p-4">
              <div className="flex justify-between items-center mb-4">
                <h2 className="font-semibold text-zinc-900">
                  {insertPoint ? 'Inserisci step tra...' : 'Aggiungi step'}
                </h2>
                <button onClick={() => { setShowPicker(false); setInsertPoint(null) }} className="text-zinc-400 hover:text-zinc-600">
                  <X className="w-5 h-5" />
                </button>
              </div>
              {Object.entries(modulesData.grouped).map(([cat, mods]) => {
                const s = catStyle(cat)
                return (
                  <div key={cat} className="mb-4">
                    <div className="flex items-center gap-2 mb-2">
                      {s.icon}
                      <span className="text-xs font-semibold uppercase text-zinc-500 tracking-wide">{cat}</span>
                    </div>
                    <div className="space-y-1">
                      {mods.map(m => (
                        <button
                          key={m.key}
                          onClick={() => addNode(m.key)}
                          className="w-full text-left p-2 rounded hover:bg-zinc-50 border border-transparent hover:border-zinc-200"
                        >
                          <div className="text-sm font-medium text-zinc-900">{m.label}</div>
                          <div className="text-[11px] text-zinc-500 leading-snug mt-0.5">{m.description}</div>
                        </button>
                      ))}
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {!showPicker && selectedNode && selectedMeta && (
            <NodeEditor
              key={selectedNode.id}
              node={selectedNode}
              meta={selectedMeta}
              onSave={saveSelected}
              onDelete={deleteSelected}
              onClose={() => setSelectedId(null)}
              saving={saving}
              isComposite={isComposite}
            />
          )}
        </div>
      )}
    </div>
  )
}

/* ────────── Rendering catena di step ────────── */

function StepChain({
  steps,
  edges,
  selectedId,
  onSelect,
  onInsert,
}: {
  steps: TreeNode[]
  edges: GraphEdge[]
  selectedId: number | null
  onSelect: (id: number) => void
  onInsert: (fromId: number, fromPort: string, toId: number) => void
}) {
  return (
    <div className="space-y-0">
      {steps.map((step, i) => {
        const prevStep = i > 0 ? steps[i - 1] : null
        const prevEdge = prevStep
          ? edges.find(e => e.from_node_id === prevStep.node.id && e.to_node_id === step.node.id)
          : null

        return (
          <div key={step.node.id}>
            {/* "+" insert button between steps */}
            {prevStep && prevEdge && (
              <InsertLine onClick={() => onInsert(prevEdge.from_node_id, prevEdge.from_port, prevEdge.to_node_id)} />
            )}
            {!prevStep && i > 0 && <Connector />}

            {step.kind === 'goto' ? (
              <GotoCard label={step.gotoLabel ?? '???'} onClick={() => onSelect(step.node.id)} />
            ) : step.kind === 'branch' && step.branches ? (
              <>
                <StepCard
                  node={step.node}
                  isSelected={selectedId === step.node.id}
                  onClick={() => onSelect(step.node.id)}
                />
                <BranchView
                  branches={step.branches}
                  edges={edges}
                  selectedId={selectedId}
                  onSelect={onSelect}
                  onInsert={onInsert}
                />
              </>
            ) : (
              <StepCard
                node={step.node}
                isSelected={selectedId === step.node.id}
                onClick={() => onSelect(step.node.id)}
              />
            )}
          </div>
        )
      })}
    </div>
  )
}

/* ────────── Card singolo step ────────── */

function StepCard({ node, isSelected, onClick }: { node: GraphNode; isSelected: boolean; onClick: () => void }) {
  const s = catStyle(node.category)
  const config = node.config ?? {}
  const text = String(config.text ?? config.question ?? '')
  const buttons = Array.isArray(config.buttons) ? (config.buttons as { label: string }[]) : []

  return (
    <div
      onClick={onClick}
      className={`
        relative border-l-4 ${s.border} bg-white rounded-lg border border-zinc-200
        p-4 cursor-pointer transition-all hover:shadow-md
        ${isSelected ? 'ring-2 ring-emerald-500 shadow-md' : ''}
      `}
    >
      {/* Header */}
      <div className="flex items-center gap-2 mb-1">
        {s.icon}
        <span className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{s.verb}</span>
        {node.is_entry && (
          <span className="ml-auto text-[10px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded font-medium">
            {node.entry_trigger === 'first_message' ? 'Primo messaggio' : node.entry_trigger ?? 'Trigger'}
          </span>
        )}
      </div>

      {/* Title */}
      <div className="font-medium text-zinc-900 text-sm">
        {node.label || node.module_label}
      </div>

      {/* Text preview */}
      {text ? (
        <div className="mt-2 text-sm text-zinc-600 bg-zinc-50 rounded px-3 py-2 leading-relaxed italic">
          &ldquo;{text.length > 120 ? text.slice(0, 120) + '...' : text}&rdquo;
        </div>
      ) : null}

      {/* Buttons preview */}
      {buttons.length > 0 ? (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {buttons.map((btn, bi) => (
            <span
              key={bi}
              className="inline-block text-xs bg-white border border-emerald-300 text-emerald-700 px-2.5 py-1 rounded-full"
            >
              {btn.label || `Bottone ${bi + 1}`}
            </span>
          ))}
        </div>
      ) : null}

      {/* Save-to info */}
      {config.save_to ? (
        <div className="mt-2 text-[11px] text-zinc-500 font-mono">
          💾 → {String(config.save_to)}
        </div>
      ) : null}
      {Array.isArray(config.assignments) && (config.assignments as { key: string; value: string }[]).length > 0 ? (
        <div className="mt-2 text-[11px] text-zinc-500 font-mono">
          💾 {(config.assignments as { key: string; value: string }[]).map(a => `${a.key} = ${JSON.stringify(a.value)}`).join(', ')}
        </div>
      ) : null}
    </div>
  )
}

/* ────────── Branch visualization ────────── */

function BranchView({
  branches,
  edges,
  selectedId,
  onSelect,
  onInsert,
}: {
  branches: { port: string; label: string; children: TreeNode[] }[]
  edges: GraphEdge[]
  selectedId: number | null
  onSelect: (id: number) => void
  onInsert: (fromId: number, fromPort: string, toId: number) => void
}) {
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})

  return (
    <div className="ml-4 mt-2 space-y-2 border-l-2 border-zinc-200 pl-4">
      {branches.map(branch => {
        const isCollapsed = collapsed[branch.port] ?? false
        return (
          <div key={branch.port}>
            <button
              onClick={() => setCollapsed(c => ({ ...c, [branch.port]: !isCollapsed }))}
              className="flex items-center gap-2 text-xs font-medium text-zinc-600 hover:text-zinc-900 py-1"
            >
              <CornerDownRight className="w-3.5 h-3.5 text-zinc-400" />
              <span className="bg-zinc-100 text-zinc-700 px-2 py-0.5 rounded">{branch.label}</span>
              {isCollapsed ? <ChevronRight className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
              {isCollapsed && <span className="text-zinc-400">({branch.children.length} step)</span>}
            </button>
            {!isCollapsed && branch.children.length > 0 && (
              <div className="ml-2 mt-1">
                <StepChain
                  steps={branch.children}
                  edges={edges}
                  selectedId={selectedId}
                  onSelect={onSelect}
                  onInsert={onInsert}
                />
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}

/* ────────── Goto (reference a nodo già visitato) ────────── */

function GotoCard({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <div
      onClick={onClick}
      className="flex items-center gap-2 px-4 py-2 bg-zinc-100 border border-dashed border-zinc-300 rounded-lg text-sm text-zinc-600 cursor-pointer hover:bg-zinc-200 transition-colors"
    >
      <ArrowRight className="w-4 h-4 text-zinc-400" />
      Vai a: <span className="font-medium text-zinc-800">{label}</span>
    </div>
  )
}

/* ────────── Connettore + Insert "+" ────────── */

function Connector() {
  return (
    <div className="flex justify-center py-1">
      <div className="w-px h-6 bg-zinc-300" />
    </div>
  )
}

function InsertLine({ onClick }: { onClick: () => void }) {
  return (
    <div className="flex items-center justify-center py-1 group">
      <div className="flex-1 h-px bg-zinc-200" />
      <button
        onClick={(e) => { e.stopPropagation(); onClick() }}
        className="mx-2 w-6 h-6 rounded-full bg-zinc-100 border border-zinc-300 flex items-center justify-center text-zinc-400 hover:bg-emerald-500 hover:border-emerald-500 hover:text-white transition-all opacity-40 group-hover:opacity-100"
        title="Inserisci step qui"
      >
        <Plus className="w-3.5 h-3.5" />
      </button>
      <div className="flex-1 h-px bg-zinc-200" />
    </div>
  )
}

/* ────────── Side panel: editor nodo (invariato dalla v1) ────────── */

function NodeEditor({
  node, meta, onSave, onDelete, onClose, saving, isComposite,
}: {
  node: GraphNode; meta: ModuleMeta; onSave: (p: Partial<GraphNode>) => void
  onDelete: () => void; onClose: () => void; saving: boolean; isComposite: boolean
}) {
  const s = catStyle(meta.category)
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
    const patch: Partial<GraphNode> = { label: label || null, config, is_entry: isEntry }
    if (!isComposite) patch.entry_trigger = isEntry ? entryTrigger || null : null
    onSave(patch)
  }

  return (
    <div className="p-4">
      <div className="flex justify-between items-start mb-1">
        <div>
          <div className={`inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded ${s.bg}`}>
            {s.icon}
            {s.verb}
          </div>
          <h2 className="mt-1 font-semibold text-zinc-900">{meta.label}</h2>
        </div>
        <button onClick={onClose} className="text-zinc-400 hover:text-zinc-600"><X className="w-5 h-5" /></button>
      </div>
      <p className="text-xs text-zinc-500 mb-4">{meta.description}</p>

      <div className="mb-3">
        <div className="mb-1 text-xs font-medium text-zinc-700">Titolo</div>
        <input value={label} onChange={e => setLabel(e.target.value)} placeholder={meta.label}
          className="w-full rounded border border-zinc-300 px-2 py-1.5 text-sm" />
      </div>

      <div className="mb-4 p-3 bg-zinc-50 rounded border border-zinc-200">
        <label className="flex items-center gap-2 text-sm font-medium text-zinc-700">
          <input type="checkbox" checked={isEntry} onChange={e => setIsEntry(e.target.checked)} />
          {isComposite ? 'Nodo di ingresso' : 'Punto di ingresso (trigger)'}
        </label>
        {!isComposite && isEntry && (
          <div className="mt-2">
            <input value={entryTrigger} onChange={e => setEntryTrigger(e.target.value)}
              placeholder="first_message  oppure  keyword:menu"
              className="w-full rounded border border-zinc-300 px-2 py-1.5 text-xs font-mono" />
          </div>
        )}
      </div>

      <div className="mb-4">
        <div className="text-xs font-semibold uppercase text-zinc-500 tracking-wide mb-2">Configurazione</div>
        {Object.keys(meta.config_schema).length === 0 && (
          <div className="text-xs text-zinc-500 italic">Nessuna opzione.</div>
        )}
        {Object.entries(meta.config_schema).map(([name, field]) => (
          <FieldEditor key={name} field={field} value={config[name] ?? field.default}
            onChange={v => setConfig({ ...config, [name]: v })} />
        ))}
      </div>

      <div className="flex gap-2 sticky bottom-0 bg-white pt-3 border-t border-zinc-100">
        <button onClick={save} disabled={saving}
          className="flex-1 flex items-center justify-center gap-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-3 py-2 rounded-lg disabled:opacity-50">
          <Save className="w-4 h-4" />
          {saving ? 'Salvo...' : 'Salva'}
        </button>
        <button onClick={onDelete}
          className="px-3 py-2 text-zinc-400 hover:text-rose-500 border border-zinc-200 rounded-lg">
          <Trash2 className="w-4 h-4" />
        </button>
      </div>
    </div>
  )
}
