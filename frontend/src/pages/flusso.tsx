import { useEffect, useMemo, useState } from 'react'
import {
  ReactFlow,
  Background,
  Controls,
  Handle,
  Position,
  useNodesState,
  useEdgesState,
  type Node,
  type Edge,
  type Connection,
  type NodeProps,
  MarkerType,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import dagre from '@dagrejs/dagre'
import { Link, useSearchParams } from 'react-router-dom'
import { apiFetch, useApi } from '@/hooks/use-api'
import { FieldEditor, type ConfigField } from '@/components/flow/field-editor'
import { Plus, X, Save, Trash2, ArrowLeft } from 'lucide-react'

/* ────────── Tipi ────────── */

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

/* ────────── Stili nodo n8n-style ────────── */

const CAT_ICON_COLORS: Record<string, string> = {
  trigger:   'bg-emerald-500',
  logica:    'bg-violet-500',
  invio:     'bg-sky-500',
  attesa:    'bg-amber-500',
  dati:      'bg-slate-500',
  azione:    'bg-rose-500',
  ai:        'bg-fuchsia-500',
  composito: 'bg-indigo-500',
  custom:    'bg-indigo-500',
}
const catIconColor = (c: string) => CAT_ICON_COLORS[c] ?? 'bg-slate-500'

const CAT_EMOJI: Record<string, string> = {
  trigger: '⚡', logica: '🔀', invio: '💬', attesa: '⏳',
  dati: '📊', azione: '▶️', ai: '✨', composito: '📦', custom: '📦',
}
const catEmoji = (c: string) => CAT_EMOJI[c] ?? '📋'

/* ────────── Edge labels: port name → label leggibile ────────── */

function portLabel(port: string, nodeOutputs: Record<string, string>): string {
  if (nodeOutputs[port]) {
    const label = nodeOutputs[port]
    if (label === 'Continua') return 'Poi'
    if (label === 'Nessun match') return 'Altrimenti'
    return label
  }
  if (port === 'out') return 'Poi'
  if (port === 'ok') return 'Poi'
  if (port === 'si') return 'Sì'
  if (port === 'no') return 'No'
  if (port === 'errore') return 'Errore'
  if (port === 'fallback') return 'Altrimenti'
  return port
}

/* ────────── Custom Node: Shopify-style card ────────── */

/**
 * Genera una descrizione leggibile per un nodo in base al tipo e config.
 * Es: "Chiedi: Con chi giocherai?" oppure "Se booking_type = matchmaking"
 */
function smartDescription(n: GraphNode): { title: string; detail: string; tooltip: string } {
  const c = n.config ?? {}
  const label = n.label || n.module_label
  const text = String(c.text ?? c.question ?? '')
  const shortText = text.length > 50 ? text.slice(0, 47) + '...' : text
  const buttons = Array.isArray(c.buttons) ? (c.buttons as { label: string }[]).map(b => b.label).join(' / ') : ''

  switch (n.module_key) {
    case 'primo_messaggio':
      return { title: 'Quando un utente scrive', detail: n.entry_trigger === 'first_message' ? 'Prima volta o sessione nuova' : n.entry_trigger ?? '', tooltip: 'Punto di ingresso: scatta quando arriva un messaggio senza sessione attiva' }
    case 'trigger_keyword':
      return { title: `Quando scrive "${(c.keyword as string) ?? '...'}"`, detail: 'Parola chiave riconosciuta', tooltip: 'Scatta quando il messaggio contiene questa parola chiave' }
    case 'utente_registrato':
      return { title: "L'utente è già registrato?", detail: 'Controlla se ha completato la registrazione', tooltip: 'Verifica se esiste un profilo completo (nome, età, livello, fascia oraria)' }
    case 'condizione_campo': {
      const campo = String(c.campo ?? '?')
      const valori = Array.isArray(c.valori) ? (c.valori as string[]).join(', ') : '?'
      return { title: `Se ${campo} è...`, detail: valori, tooltip: `Legge il campo "${campo}" dalla sessione e segue la porta corrispondente al valore trovato` }
    }
    case 'invia_testo':
      return { title: shortText || 'Invia un messaggio', detail: '', tooltip: `Messaggio completo: ${text}` }
    case 'invia_bottoni':
      return { title: shortText || 'Chiedi con bottoni', detail: buttons, tooltip: `Messaggio: ${text}\nBottoni: ${buttons}` }
    case 'chiedi_campo': {
      const q = String(c.question ?? '')
      const saveTo = String(c.save_to ?? '')
      return { title: q.length > 45 ? q.slice(0, 42) + '...' : q || 'Chiedi un dato', detail: saveTo ? `Salva in: ${saveTo}` : '', tooltip: `Domanda: ${q}\nValidatore: ${c.validator ?? 'qualsiasi'}\nSalva in: ${saveTo}` }
    }
    case 'attendi_input':
      return { title: 'Aspetta la risposta', detail: `Salva in: ${c.save_to ?? 'user_reply'}`, tooltip: "Mette in pausa il flusso finché l'utente non risponde" }
    case 'parse_data':
      return { title: '📅 Interpreta data e ora', detail: "Dall'ultimo messaggio dell'utente", tooltip: 'Converte testo libero ("domani alle 17.30") in data+ora strutturati' }
    case 'parse_risultato':
      return { title: '🎾 Interpreta il punteggio', detail: 'Formato ATP: 6-3 6-4, ho vinto...', tooltip: 'Capisce punteggi in vari formati: standard (6-3), compresso (63), italiano, dichiarazioni' }
    case 'verifica_calendario':
      return { title: '📅 Slot disponibile?', detail: 'Controlla su Google Calendar', tooltip: 'Verifica se lo slot richiesto è libero. Se occupato, propone alternative.' }
    case 'crea_prenotazione':
      return { title: '📋 Crea la prenotazione', detail: 'Booking + Google Calendar', tooltip: 'Crea il record nel database e l\'evento su Google Calendar con i dati della sessione' }
    case 'cancella_prenotazione':
      return { title: '❌ Cancella la prenotazione', detail: 'Rimuove booking + evento', tooltip: 'Cancella la prenotazione selezionata dal database e da Google Calendar' }
    case 'salva_profilo':
      return { title: '💾 Salva il profilo', detail: 'Nome, età, livello → database', tooltip: 'Persiste i dati raccolti durante la registrazione sulla tabella utenti' }
    case 'salva_in_sessione': {
      const assignments = Array.isArray(c.assignments) ? (c.assignments as { key: string; value: unknown }[]) : []
      const summary = assignments.map(a => `${a.key} = ${JSON.stringify(a.value)}`).join(', ')
      return { title: `💾 ${summary || 'Salva dati'}`, detail: '', tooltip: `Scrive nella sessione: ${summary}` }
    }
    case 'salva_feedback':
      return { title: '⭐ Salva il feedback', detail: 'Rating + commento → database', tooltip: 'Salva la valutazione e il commento nella tabella feedbacks' }
    case 'cerca_utente':
      return { title: '🔍 Cerca nel circolo', detail: 'Ricerca per nome (fuzzy)', tooltip: "Cerca l'avversario tra i giocatori iscritti. Mostra i risultati come bottoni per conferma." }
    case 'cerca_matchmaking':
      return { title: '🎯 Trova avversario per livello', detail: 'Ricerca per ELO simile', tooltip: 'Cerca un avversario con punteggio ELO simile (±100, ±200, ±400) e invia invito' }
    case 'aggiorna_elo':
      return { title: '🏆 Aggiorna classifica', detail: 'Calcolo ELO dopo il risultato', tooltip: 'Aggiorna il punteggio ELO di entrambi i giocatori basandosi sul risultato della partita' }
    case 'gemini_classifica': {
      const cats = Array.isArray(c.categorie) ? (c.categorie as string[]).join(', ') : ''
      return { title: '🤖 Classifica con AI', detail: cats || 'Categorie da configurare', tooltip: `Manda il testo a Gemini per classificarlo in: ${cats}` }
    }
    case 'fine_flusso':
      return { title: '🏁 Fine', detail: 'Il flusso si conclude qui', tooltip: 'Termina il flusso. La prossima volta che l\'utente scrive, partirà da un nuovo trigger.' }
    default:
      return { title: label, detail: shortText, tooltip: `Modulo: ${n.module_key}` }
  }
}

function N8nNode({ data, selected }: NodeProps) {
  const n = data as unknown as GraphNode
  const desc = smartDescription(n)
  const iconBg = catIconColor(n.category)
  const emoji = catEmoji(n.category)

  return (
    <div
      title={desc.tooltip}
      className={`
        flex items-center gap-2.5 rounded-lg px-1.5 py-1.5 transition-all cursor-pointer border
        ${selected
          ? 'bg-white ring-2 ring-blue-500 shadow-lg border-blue-300'
          : 'bg-white hover:shadow-md shadow-sm border-zinc-200'
        }
      `}
      style={{ minWidth: 180, maxWidth: 260 }}
    >
      {/* Target handle */}
      <Handle type="target" position={Position.Top} id="in"
        className="!w-2.5 !h-2.5 !bg-zinc-400 !border-2 !border-white !-top-1" />

      {/* Icon circle */}
      <div className={`shrink-0 w-9 h-9 rounded-lg ${iconBg} flex items-center justify-center text-white text-base shadow-sm`}>
        {emoji}
      </div>

      {/* Text */}
      <div className="min-w-0 flex-1 py-1 pr-2">
        <div className="text-[12px] font-semibold text-zinc-800 leading-tight truncate">
          {desc.title}
        </div>
        {desc.detail ? (
          <div className="text-[10px] text-zinc-400 leading-tight truncate mt-0.5">
            {desc.detail}
          </div>
        ) : null}
      </div>

      {/* Source handles */}
      {Object.keys(n.outputs).map((port, i, arr) => (
        <Handle
          key={port}
          type="source"
          position={Position.Bottom}
          id={port}
          className="!w-2.5 !h-2.5 !bg-zinc-400 !border-2 !border-white !-bottom-1"
          style={{ left: arr.length > 1 ? `${15 + (i * 70 / Math.max(arr.length - 1, 1))}%` : '50%' }}
        />
      ))}
    </div>
  )
}

const nodeTypes = { n8n: N8nNode }

/* ────────── Dagre auto-layout (Left → Right) ────────── */

/**
 * Trova le componenti connesse del grafo e le dispone con dagre
 * in tre colonne: PRE-PARTITA | PRINCIPALE | POST-PARTITA.
 *
 * La classificazione avviene in base all'entry_trigger dei nodi entry:
 *  - scheduler:reminder_* → pre-partita (sinistra)
 *  - scheduler:post_match → post-partita (destra)
 *  - tutto il resto → principale (centro)
 */
function layoutGraph(
  graphNodes: GraphNode[],
  graphEdges: GraphEdge[],
): { nodes: Node[]; edges: Edge[] } {

  // 1. Trova componenti connesse
  const adjMap = new Map<number, Set<number>>()
  for (const n of graphNodes) adjMap.set(n.id, new Set())
  for (const e of graphEdges) {
    adjMap.get(e.from_node_id)?.add(e.to_node_id)
    adjMap.get(e.to_node_id)?.add(e.from_node_id)
  }

  const visited = new Set<number>()
  const components: number[][] = []
  for (const n of graphNodes) {
    if (visited.has(n.id)) continue
    const comp: number[] = []
    const stack = [n.id]
    while (stack.length > 0) {
      const cur = stack.pop()!
      if (visited.has(cur)) continue
      visited.add(cur)
      comp.push(cur)
      for (const nb of adjMap.get(cur) ?? []) {
        if (!visited.has(nb)) stack.push(nb)
      }
    }
    components.push(comp)
  }

  // 2. Classifica ogni componente
  const nodeMap = new Map(graphNodes.map(n => [n.id, n]))

  type CompGroup = 'pre' | 'main' | 'post'
  const classified: { group: CompGroup; ids: number[] }[] = components.map(ids => {
    const entries = ids.map(id => nodeMap.get(id)).filter(n => n?.is_entry)
    const trigger = entries[0]?.entry_trigger ?? ''

    if (trigger.startsWith('scheduler:reminder')) return { group: 'pre', ids }
    if (trigger.startsWith('scheduler:post_match') || trigger.startsWith('scheduler:result') || trigger.startsWith('scheduler:feedback')) return { group: 'post', ids }
    return { group: 'main', ids }
  })

  // 3. Layout ogni gruppo con dagre, poi offset orizzontale
  const allPositioned: { id: number; x: number; y: number }[] = []
  const groupOffsetX: Record<CompGroup, number> = { pre: -800, main: 0, post: 800 }
  const groupWidths: Record<CompGroup, number> = { pre: 0, main: 0, post: 0 }

  // Raggruppa componenti per gruppo
  const byGroup: Record<CompGroup, number[][]> = { pre: [], main: [], post: [] }
  for (const c of classified) byGroup[c.group].push(c.ids)

  for (const group of ['pre', 'main', 'post'] as CompGroup[]) {
    let yOffset = 0
    for (const compIds of byGroup[group]) {
      const compNodes = compIds.map(id => nodeMap.get(id)!).filter(Boolean)
      const compEdges = graphEdges.filter(e => compIds.includes(e.from_node_id) && compIds.includes(e.to_node_id))

      const g = new dagre.graphlib.Graph()
      g.setDefaultEdgeLabel(() => ({}))
      g.setGraph({ rankdir: 'TB', nodesep: 100, ranksep: 120, marginx: 40, marginy: 40 })

      for (const n of compNodes) g.setNode(String(n.id), { width: 200, height: 50 })
      for (const e of compEdges) g.setEdge(String(e.from_node_id), String(e.to_node_id))

      dagre.layout(g)

      let maxY = 0
      for (const n of compNodes) {
        const pos = g.node(String(n.id))
        if (!pos) continue
        allPositioned.push({
          id: n.id,
          x: (pos.x ?? 0) + groupOffsetX[group],
          y: (pos.y ?? 0) + yOffset,
        })
        maxY = Math.max(maxY, (pos.y ?? 0) + 100)
        groupWidths[group] = Math.max(groupWidths[group], (pos.x ?? 0) + 250)
      }

      yOffset += maxY + 60 // spazio tra componenti dello stesso gruppo
    }
  }

  const posMap = new Map(allPositioned.map(p => [p.id, p]))

  const nodes: Node[] = graphNodes.map(n => {
    const pos = posMap.get(n.id)
    return {
      id: String(n.id),
      type: 'n8n',
      position: { x: (pos?.x ?? 0) - 120, y: (pos?.y ?? 0) - 45 },
      data: n as unknown as Record<string, unknown>,
      selected: false,
    }
  })

  const edges: Edge[] = graphEdges.map(e => {
    const sourceNode = nodeMap.get(e.from_node_id)
    const label = sourceNode ? portLabel(e.from_port, sourceNode.outputs) : 'Poi'

    return {
      id: String(e.id),
      source: String(e.from_node_id),
      sourceHandle: e.from_port,
      target: String(e.to_node_id),
      targetHandle: e.to_port,
      type: 'smoothstep',
      animated: false,
      label,
      labelStyle: { fontSize: 10, fontWeight: 500, fill: '#9ca3af' },
      labelBgStyle: { fill: '#f9fafb', fillOpacity: 0.9 },
      labelBgPadding: [6, 3] as [number, number],
      labelBgBorderRadius: 4,
      style: { stroke: '#6b7280', strokeWidth: 1.5 },
      markerEnd: { type: MarkerType.ArrowClosed, color: '#6b7280', width: 14, height: 14 },
    }
  })

  return { nodes, edges }
}

/* ────────── Pagina principale ────────── */

export function Flusso() {
  const [searchParams] = useSearchParams()
  const compositeId = searchParams.get('composite')
  const isComposite = compositeId !== null
  const apiBase = isComposite ? `/admin/flow/composites/${compositeId}` : '/admin/flow'

  const { data: modulesData } = useApi<ModulesResponse>('/admin/flow/modules')
  const { data: graphData, refetch } = useApi<GraphResponse>(`${apiBase}/graph`)

  const [nodes, setNodes, onNodesChange] = useNodesState<Node>([])
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [showPicker, setShowPicker] = useState(false)
  const [saving, setSaving] = useState(false)

  // Auto-layout on data change
  useEffect(() => {
    if (!graphData) return
    const { nodes: ln, edges: le } = layoutGraph(graphData.nodes, graphData.edges)
    setNodes(ln)
    setEdges(le)
  }, [graphData, setNodes, setEdges])

  const selectedNode = useMemo(
    () => graphData?.nodes.find(n => n.id === selectedId) ?? null,
    [selectedId, graphData],
  )
  const selectedMeta = useMemo(() => {
    if (!selectedNode || !modulesData) return null
    return Object.values(modulesData.grouped).flat().find(m => m.key === selectedNode.module_key) ?? null
  }, [selectedNode, modulesData])

  const onConnect = async (conn: Connection) => {
    if (!conn.source || !conn.target) return
    try {
      await apiFetch(`${apiBase}/edges`, {
        method: 'POST',
        body: JSON.stringify({
          from_node_id: Number(conn.source),
          from_port: conn.sourceHandle ?? 'out',
          to_node_id: Number(conn.target),
          to_port: conn.targetHandle ?? 'in',
        }),
      })
      refetch()
    } catch (e) {
      console.error('createEdge failed', e)
    }
  }

  const addNode = async (moduleKey: string) => {
    try {
      await apiFetch(`${apiBase}/nodes`, {
        method: 'POST',
        body: JSON.stringify({ module_key: moduleKey, position: { x: 0, y: 0 }, config: {} }),
      })
      refetch()
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

  return (
    <div className="flex h-[calc(100vh-4rem)]">
      {/* ── Canvas ── */}
      <div className="flex-1 relative bg-zinc-50">
        <ReactFlow
          nodes={nodes}
          edges={edges}
          onNodesChange={onNodesChange}
          onEdgesChange={onEdgesChange}
          onConnect={onConnect}
          onNodeClick={(_, node) => { setSelectedId(Number(node.id)); setShowPicker(false) }}
          onPaneClick={() => { setSelectedId(null); setShowPicker(false) }}
          nodeTypes={nodeTypes}
          fitView
          fitViewOptions={{ padding: 0.15 }}
          proOptions={{ hideAttribution: true }}
          defaultEdgeOptions={{ type: 'smoothstep' }}
        >
          <Background color="#e5e7eb" gap={20} size={1} />
          <Controls className="!bottom-4 !left-4" />
        </ReactFlow>

        {/* Toolbar */}
        <div className="absolute top-4 left-4 z-10 flex gap-2 items-center">
          {isComposite && (
            <Link to="/moduli" className="bg-white border border-zinc-200 rounded-lg px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-50 flex items-center gap-1.5 shadow-sm">
              <ArrowLeft className="w-4 h-4" />
              Indietro
            </Link>
          )}
          <button
            onClick={() => { setShowPicker(!showPicker); setSelectedId(null) }}
            className="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm flex items-center gap-1.5"
          >
            <Plus className="w-4 h-4" />
            Aggiungi step
          </button>
          <div className="bg-white border border-zinc-200 rounded-lg px-3 py-2 text-xs text-zinc-500 shadow-sm">
            {isComposite && graphData?.composite
              ? graphData.composite.label
              : 'Flusso principale'
            } · {graphData?.nodes.length ?? 0} step
          </div>
        </div>
      </div>

      {/* ── Side panel ── */}
      {(showPicker || selectedNode) && (
        <div className="w-96 border-l border-zinc-200 bg-white overflow-y-auto shrink-0">
          {showPicker && modulesData && (
            <div className="p-4">
              <div className="flex justify-between items-center mb-4">
                <h2 className="font-semibold text-zinc-900">Aggiungi step</h2>
                <button onClick={() => setShowPicker(false)} className="text-zinc-400 hover:text-zinc-600">
                  <X className="w-5 h-5" />
                </button>
              </div>
              {Object.entries(modulesData.grouped).map(([cat, mods]) => {
                return (
                  <div key={cat} className="mb-4">
                    <div className="text-xs font-semibold uppercase tracking-wide mb-2 text-zinc-500 flex items-center gap-1.5">
                      <span>{catEmoji(cat)}</span>{cat}
                    </div>
                    <div className="space-y-1">
                      {mods.map(m => (
                        <button key={m.key} onClick={() => addNode(m.key)}
                          className="w-full text-left p-2.5 rounded-lg hover:bg-zinc-50 border border-transparent hover:border-zinc-200 transition-colors">
                          <div className="text-sm font-medium text-zinc-900">{m.label}</div>
                          <div className="text-[11px] text-zinc-500 mt-0.5">{m.description}</div>
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

/* ────────── Side panel editor ────────── */

function NodeEditor({
  node, meta, onSave, onDelete, onClose, saving, isComposite,
}: {
  node: GraphNode; meta: ModuleMeta; onSave: (p: Partial<GraphNode>) => void
  onDelete: () => void; onClose: () => void; saving: boolean; isComposite: boolean
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
    const patch: Partial<GraphNode> = { label: label || null, config, is_entry: isEntry }
    if (!isComposite) patch.entry_trigger = isEntry ? entryTrigger || null : null
    onSave(patch)
  }

  return (
    <div className="p-4">
      <div className="flex justify-between items-start mb-3">
        <div>
          <div className="text-[11px] font-medium text-zinc-500">{catEmoji(meta.category)} {meta.category}</div>
          <h2 className="text-lg font-semibold text-zinc-900">{meta.label}</h2>
        </div>
        <button onClick={onClose} className="text-zinc-400 hover:text-zinc-600"><X className="w-5 h-5" /></button>
      </div>
      <p className="text-xs text-zinc-500 mb-4">{meta.description}</p>

      <div className="mb-3">
        <div className="mb-1 text-xs font-medium text-zinc-700">Titolo visibile</div>
        <input value={label} onChange={e => setLabel(e.target.value)} placeholder={meta.label}
          className="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
      </div>

      <div className="mb-4 p-3 bg-zinc-50 rounded-lg border border-zinc-200">
        <label className="flex items-center gap-2 text-sm font-medium text-zinc-700">
          <input type="checkbox" checked={isEntry} onChange={e => setIsEntry(e.target.checked)} className="rounded" />
          {isComposite ? 'Nodo di ingresso' : 'Punto di ingresso (trigger)'}
        </label>
        {!isComposite && isEntry && (
          <input value={entryTrigger} onChange={e => setEntryTrigger(e.target.value)}
            placeholder="first_message oppure keyword:menu"
            className="w-full mt-2 rounded border border-zinc-300 px-2 py-1.5 text-xs font-mono" />
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
          className="flex-1 flex items-center justify-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-3 py-2 rounded-lg disabled:opacity-50">
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
