import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  Controls,
  MiniMap,
  Panel,
  Handle,
  Position,
  MarkerType,
  applyNodeChanges,
  applyEdgeChanges,
  type Node,
  type Edge,
  type NodeChange,
  type EdgeChange,
  type NodeProps,
  type Connection,
  useReactFlow,
} from '@xyflow/react'
import dagre from '@dagrejs/dagre'
import {
  Loader2, Save, Plus, Trash2, X, Cpu, Cog, MessageSquareText,
  Zap, AlertTriangle, Check, Search, MousePointerClick,
  Filter, GitBranch, Type, Hash, ListChecks, Code, FileText,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { apiFetch } from '@/hooks/use-api'

import '@xyflow/react/dist/style.css'

/* ═════════════════════════════════════════════════════════════════
 *  Types
 * ═════════════════════════════════════════════════════════════════ */

interface FlowButton {
  label: string
  target_state: string
  value?: string
  side_effect?: string
}

type RuleType = 'name' | 'integer_range' | 'mapping' | 'regex' | 'free_text'

interface InputRule {
  type: RuleType
  // Common
  save_to?: string
  next_state?: string
  error_key?: string
  side_effect?: string
  transform?: string
  // integer_range
  min?: number
  max?: number
  // mapping
  options?: string[]
  // regex
  pattern?: string
  capture_group?: number
}

interface Transition {
  if?: Record<string, string | boolean | number>
  then: string
}

interface FlowStateNode {
  id: string
  state: string
  type: 'simple' | 'complex'
  is_custom: boolean
  category: string
  description: string | null
  message_key: string
  message_text: string | null
  fallback_key: string | null
  fallback_text: string | null
  buttons: FlowButton[]
  input_rules: InputRule[]
  transitions: Transition[]
  position: { x: number; y: number } | null
  sort_order: number
}

interface ButtonEdge {
  id: string
  source: string
  target: string
  label: string
  kind: 'button' | 'rule' | 'transition'
  side_effect: string | null
  editable: boolean
}

interface CodeEdge {
  id: string
  source: string
  target: string
  label: null
  kind: 'code'
  side_effect: null
  editable: false
}

interface GraphResponse {
  nodes: FlowStateNode[]
  buttonEdges: ButtonEdge[]
  codeEdges: CodeEdge[]
}

interface RuleTypeMeta {
  label: string
  description: string
  fields: { key: string; label: string; type: string; placeholder?: string }[]
}

interface MetaResponse {
  side_effects: Record<string, string>
  messages: { key: string; category: string; description: string | null }[]
  built_in: string[]
  categories: string[]
  rule_types: Record<string, RuleTypeMeta>
  transforms: Record<string, string>
  transition_fields: Record<string, string>
  transition_operators: Record<string, string>
}

const ruleTypeIcons: Record<RuleType, typeof Type> = {
  name:          Type,
  integer_range: Hash,
  mapping:       ListChecks,
  regex:         Code,
  free_text:     FileText,
}

type FlowNodeData = FlowStateNode & { selected?: boolean }

/* ═════════════════════════════════════════════════════════════════
 *  Color helpers
 * ═════════════════════════════════════════════════════════════════ */

const categoryColors: Record<string, { bg: string; border: string; text: string }> = {
  onboarding:  { bg: 'bg-blue-50',    border: 'border-blue-400',    text: 'text-blue-700' },
  menu:        { bg: 'bg-emerald-50', border: 'border-emerald-400', text: 'text-emerald-700' },
  prenotazione:{ bg: 'bg-amber-50',   border: 'border-amber-400',   text: 'text-amber-700' },
  conferma:    { bg: 'bg-purple-50',  border: 'border-purple-400',  text: 'text-purple-700' },
  matchmaking: { bg: 'bg-orange-50',  border: 'border-orange-400',  text: 'text-orange-700' },
  gestione:    { bg: 'bg-cyan-50',    border: 'border-cyan-400',    text: 'text-cyan-700' },
  profilo:     { bg: 'bg-pink-50',    border: 'border-pink-400',    text: 'text-pink-700' },
  risultati:   { bg: 'bg-red-50',     border: 'border-red-400',     text: 'text-red-700' },
  feedback:    { bg: 'bg-yellow-50',  border: 'border-yellow-400',  text: 'text-yellow-700' },
  avversario:  { bg: 'bg-violet-50',  border: 'border-violet-400',  text: 'text-violet-700' },
  errore:      { bg: 'bg-gray-100',   border: 'border-gray-400',    text: 'text-gray-700' },
  custom:      { bg: 'bg-teal-50',    border: 'border-teal-400',    text: 'text-teal-700' },
}

function getCategoryColor(category: string) {
  return categoryColors[category] ?? categoryColors.errore
}

/* ═════════════════════════════════════════════════════════════════
 *  Custom node component
 * ═════════════════════════════════════════════════════════════════ */

function StateNode({ data, selected }: NodeProps) {
  const node = data as unknown as FlowNodeData
  const colors = getCategoryColor(node.category)
  const messagePreview = node.message_text
    ? node.message_text.length > 80
      ? node.message_text.slice(0, 77) + '…'
      : node.message_text
    : '—'

  return (
    <div
      className={`rounded-lg border-2 shadow-sm transition-all ${colors.bg} ${
        selected ? 'border-emerald-500 ring-2 ring-emerald-300' : colors.border
      }`}
      style={{ minWidth: 240, maxWidth: 280 }}
    >
      <Handle type="target" position={Position.Top} className="!bg-emerald-500 !w-2 !h-2" />

      {/* Header */}
      <div className="px-3 py-2 border-b border-black/10 flex items-center justify-between gap-2">
        <code className={`text-xs font-bold font-mono ${colors.text}`}>{node.state}</code>
        <div className="flex items-center gap-1">
          {node.is_custom && (
            <span className="inline-flex items-center gap-0.5 rounded bg-teal-200 px-1 py-0.5 text-[9px] font-bold text-teal-800">
              <Zap className="h-2.5 w-2.5" /> custom
            </span>
          )}
          {node.type === 'simple' ? (
            <Cog className="h-3 w-3 text-gray-500" />
          ) : (
            <Cpu className="h-3 w-3 text-amber-600" />
          )}
        </div>
      </div>

      {/* Message preview */}
      <div className="px-3 py-2">
        <div className="flex items-start gap-1.5 text-[10px] text-gray-600 mb-1">
          <MessageSquareText className="h-2.5 w-2.5 mt-0.5 shrink-0" />
          <code className="font-mono bg-white/60 rounded px-1">{node.message_key}</code>
        </div>
        <p className="text-[11px] text-gray-700 leading-snug whitespace-pre-line">
          {messagePreview}
        </p>
      </div>

      {/* Buttons */}
      {node.buttons.length > 0 && (
        <div className="px-3 pb-2 space-y-1">
          {node.buttons.map((btn, i) => (
            <div
              key={i}
              className="flex items-center justify-between gap-1 rounded bg-white/70 border border-black/10 px-2 py-1 text-[10px]"
            >
              <span className="truncate font-medium">{btn.label}</span>
              <span className="font-mono text-gray-500 truncate text-[9px]">
                → {btn.target_state}
              </span>
            </div>
          ))}
        </div>
      )}

      <Handle type="source" position={Position.Bottom} className="!bg-emerald-500 !w-2 !h-2" />
    </div>
  )
}

const nodeTypes = { stateNode: StateNode }

/* ═════════════════════════════════════════════════════════════════
 *  Auto-layout (dagre)
 * ═════════════════════════════════════════════════════════════════ */

function autoLayout(nodes: Node[], edges: Edge[]): Node[] {
  const g = new dagre.graphlib.Graph()
  g.setDefaultEdgeLabel(() => ({}))
  g.setGraph({ rankdir: 'TB', nodesep: 60, ranksep: 90, marginx: 40, marginy: 40 })

  nodes.forEach(n => g.setNode(n.id, { width: 260, height: 160 }))
  edges.forEach(e => g.setEdge(e.source, e.target))

  dagre.layout(g)

  return nodes.map(n => {
    const pos = g.node(n.id)
    return {
      ...n,
      position: { x: pos.x - 130, y: pos.y - 80 },
    }
  })
}

/* ═════════════════════════════════════════════════════════════════
 *  Live tester for input rules
 * ═════════════════════════════════════════════════════════════════ */

/**
 * Tester locale TypeScript-side. Replica la logica del PHP RuleEvaluator
 * per dare feedback live nell'editor (verde se la rule matcha, rosso se no).
 */
function testRule(rule: InputRule, input: string): { ok: boolean; value?: string } {
  const clean = input.trim()
  if (!clean) return { ok: false }

  switch (rule.type) {
    case 'name': {
      // /^[\p{L}\s']{2,60}$/u
      const ok = /^[\p{L}\s']{2,60}$/u.test(clean)
      return { ok, value: ok ? toTitleCase(clean) : undefined }
    }
    case 'integer_range': {
      const m = clean.match(/(\d+)/)
      if (!m) return { ok: false }
      const v = parseInt(m[1], 10)
      if (rule.min !== undefined && v < rule.min) return { ok: false }
      if (rule.max !== undefined && v > rule.max) return { ok: false }
      return { ok: true, value: String(v) }
    }
    case 'mapping': {
      const lower = clean.toLowerCase()
      for (const line of rule.options ?? []) {
        if (!line.includes(':')) continue
        const [canonical, syns] = line.split(':').map(s => s.trim())
        const allKw = [canonical, ...syns.split(',').map(s => s.trim())].map(s => s.toLowerCase())
        for (const kw of allKw) {
          if (kw && (lower.includes(kw) || kw === lower)) {
            return { ok: true, value: applyTransform(canonical, rule.transform) }
          }
        }
      }
      return { ok: false }
    }
    case 'regex': {
      if (!rule.pattern) return { ok: false }
      try {
        const re = new RegExp(rule.pattern, 'u')
        const m = clean.match(re)
        if (!m) return { ok: false }
        const captured = rule.capture_group != null ? m[rule.capture_group] ?? m[0] : m[0]
        return { ok: true, value: applyTransform(captured, rule.transform) }
      } catch {
        return { ok: false }
      }
    }
    case 'free_text':
      return { ok: true, value: clean }
    default:
      return { ok: false }
  }
}

function toTitleCase(s: string): string {
  return s.toLowerCase().replace(/(^|\s)\p{L}/gu, c => c.toUpperCase())
}

function applyTransform(value: string, t?: string): string {
  switch (t) {
    case 'title_case': return toTitleCase(value)
    case 'lowercase':  return value.toLowerCase()
    case 'uppercase':  return value.toUpperCase()
    case 'int':        return String(parseInt(value, 10) || 0)
    default:           return value
  }
}

/* ═════════════════════════════════════════════════════════════════
 *  Sub-editor: una singola input_rule
 * ═════════════════════════════════════════════════════════════════ */

function RuleCard({
  rule, index, allTargets, meta, onChange, onRemove,
}: {
  rule: InputRule
  index: number
  allTargets: string[]
  meta: MetaResponse | null
  onChange: (patch: Partial<InputRule>) => void
  onRemove: () => void
}) {
  const [tester, setTester] = useState('')
  const Icon = ruleTypeIcons[rule.type] ?? Type
  const typeMeta = meta?.rule_types[rule.type]

  const result = useMemo(() => testRule(rule, tester), [rule, tester])

  return (
    <div className="rounded-lg border bg-muted/30 p-3 space-y-2.5">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 min-w-0">
          <div className="rounded bg-emerald-100 p-1">
            <Icon className="h-3 w-3 text-emerald-700" />
          </div>
          <span className="text-xs font-medium">Regola #{index + 1}</span>
          <span className="text-[10px] text-muted-foreground truncate">{typeMeta?.label}</span>
        </div>
        <button onClick={onRemove} className="p-1 text-red-400 hover:text-red-600">
          <X className="h-3 w-3" />
        </button>
      </div>

      {typeMeta?.description && (
        <p className="text-[10px] text-muted-foreground italic">{typeMeta.description}</p>
      )}

      {/* Type-specific fields */}
      {rule.type === 'integer_range' && (
        <div className="grid grid-cols-2 gap-2">
          <div>
            <label className="text-[10px] text-muted-foreground">Minimo</label>
            <input
              type="number"
              value={rule.min ?? ''}
              onChange={e => onChange({ min: e.target.value === '' ? undefined : Number(e.target.value) })}
              className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
            />
          </div>
          <div>
            <label className="text-[10px] text-muted-foreground">Massimo</label>
            <input
              type="number"
              value={rule.max ?? ''}
              onChange={e => onChange({ max: e.target.value === '' ? undefined : Number(e.target.value) })}
              className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
            />
          </div>
        </div>
      )}

      {rule.type === 'mapping' && (
        <div>
          <label className="text-[10px] text-muted-foreground">Opzioni (una per riga, formato <code>valore: sin1, sin2</code>)</label>
          <textarea
            value={(rule.options ?? []).join('\n')}
            onChange={e => onChange({ options: e.target.value.split('\n').filter(l => l.trim()) })}
            rows={4}
            placeholder={'mattina: mattino, presto\npomeriggio: dopopranzo\nsera: serale, tardi, dopo cena'}
            className="mt-0.5 w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
          />
        </div>
      )}

      {rule.type === 'regex' && (
        <div className="space-y-1.5">
          <div>
            <label className="text-[10px] text-muted-foreground">Pattern (PCRE, senza delimitatori)</label>
            <input
              value={rule.pattern ?? ''}
              onChange={e => onChange({ pattern: e.target.value })}
              placeholder="es. ^([1-4])[.,]([1-6])$"
              className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
            />
          </div>
          <div>
            <label className="text-[10px] text-muted-foreground">Gruppo da catturare (0=tutto)</label>
            <input
              type="number"
              value={rule.capture_group ?? 0}
              onChange={e => onChange({ capture_group: Number(e.target.value) })}
              className="w-24 rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
            />
          </div>
        </div>
      )}

      {/* Transform */}
      <div>
        <label className="text-[10px] text-muted-foreground">Trasforma il valore</label>
        <select
          value={rule.transform ?? 'none'}
          onChange={e => onChange({ transform: e.target.value === 'none' ? undefined : e.target.value })}
          className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
        >
          {Object.entries(meta?.transforms ?? {}).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
      </div>

      {/* Save to */}
      <div>
        <label className="text-[10px] text-muted-foreground">Salva in</label>
        <select
          value={rule.save_to ?? ''}
          onChange={e => onChange({ save_to: e.target.value || undefined })}
          className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
        >
          <option value="">— non salvare —</option>
          <optgroup label="Profilo utente">
            <option value="profile.name">profile.name</option>
            <option value="profile.is_fit">profile.is_fit</option>
            <option value="profile.fit_rating">profile.fit_rating</option>
            <option value="profile.self_level">profile.self_level</option>
            <option value="profile.age">profile.age</option>
            <option value="profile.preferred_slots">profile.preferred_slots</option>
          </optgroup>
          <optgroup label="Sessione">
            <option value="data.booking_type">data.booking_type</option>
            <option value="data.payment_method">data.payment_method</option>
            <option value="data.update_field">data.update_field</option>
          </optgroup>
        </select>
      </div>

      {/* Next state */}
      <div>
        <label className="text-[10px] text-muted-foreground">Vai allo stato</label>
        <select
          value={rule.next_state ?? ''}
          onChange={e => onChange({ next_state: e.target.value || undefined })}
          className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
        >
          <option value="">— resta qui —</option>
          {allTargets.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
      </div>

      {/* Error key */}
      <div>
        <label className="text-[10px] text-muted-foreground">Messaggio in caso di errore</label>
        <select
          value={rule.error_key ?? ''}
          onChange={e => onChange({ error_key: e.target.value || undefined })}
          className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
        >
          <option value="">— default —</option>
          {(meta?.messages ?? []).map(m => (
            <option key={m.key} value={m.key}>[{m.category}] {m.key}</option>
          ))}
        </select>
      </div>

      {/* Side effect */}
      <div>
        <label className="text-[10px] text-muted-foreground">Side effect (opzionale)</label>
        <select
          value={rule.side_effect ?? ''}
          onChange={e => onChange({ side_effect: e.target.value || undefined })}
          className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
        >
          <option value="">— nessuno —</option>
          {Object.entries(meta?.side_effects ?? {}).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
      </div>

      {/* Live tester */}
      <div className="pt-2 border-t border-dashed">
        <label className="text-[10px] text-muted-foreground flex items-center gap-1 mb-1">
          <MousePointerClick className="h-2.5 w-2.5" /> Prova qui
        </label>
        <input
          value={tester}
          onChange={e => setTester(e.target.value)}
          placeholder="Scrivi un input di prova..."
          className={`w-full rounded border-2 bg-background px-2 py-1 text-xs outline-none ${
            tester === '' ? 'border-input' : result.ok ? 'border-emerald-500' : 'border-red-400'
          }`}
        />
        {tester !== '' && (
          <p className={`text-[10px] mt-1 ${result.ok ? 'text-emerald-700' : 'text-red-600'}`}>
            {result.ok
              ? <>✓ Match{result.value !== undefined && <> → <code className="bg-white/60 rounded px-1">{result.value}</code></>}</>
              : <>✗ Non matcha</>}
          </p>
        )}
      </div>
    </div>
  )
}

/* ═════════════════════════════════════════════════════════════════
 *  Sub-editor: una singola transition
 * ═════════════════════════════════════════════════════════════════ */

function TransitionCard({
  transition, index, allTargets, meta, onChange, onRemove,
}: {
  transition: Transition
  index: number
  allTargets: string[]
  meta: MetaResponse | null
  onChange: (patch: Partial<Transition>) => void
  onRemove: () => void
}) {
  const ifEntries = Object.entries(transition.if ?? {})
  const isElse = ifEntries.length === 0

  const updateCondition = (oldKey: string | null, newKey: string, newVal: string) => {
    const next = { ...(transition.if ?? {}) }
    if (oldKey && oldKey !== newKey) delete next[oldKey]
    if (newKey) next[newKey] = newVal
    onChange({ if: next })
  }

  const removeCondition = (key: string) => {
    const next = { ...(transition.if ?? {}) }
    delete next[key]
    onChange({ if: next })
  }

  const addCondition = () => {
    const next = { ...(transition.if ?? {}) }
    next['data.booking_type'] = ''
    onChange({ if: next })
  }

  return (
    <div className="rounded-lg border bg-muted/30 p-3 space-y-2">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="rounded bg-amber-100 p-1">
            <GitBranch className="h-3 w-3 text-amber-700" />
          </div>
          <span className="text-xs font-medium">
            {isElse ? 'Altrimenti (default)' : `Se #${index + 1}`}
          </span>
        </div>
        <button onClick={onRemove} className="p-1 text-red-400 hover:text-red-600">
          <X className="h-3 w-3" />
        </button>
      </div>

      {/* Conditions */}
      {!isElse && (
        <div className="space-y-1.5">
          {ifEntries.map(([k, v], i) => (
            <div key={i} className="flex items-center gap-1">
              <select
                value={k}
                onChange={e => updateCondition(k, e.target.value, String(v))}
                className="flex-1 rounded border bg-background px-1.5 py-1 text-[10px] outline-none focus:border-emerald-500"
              >
                {Object.entries(meta?.transition_fields ?? {}).map(([fk, fl]) => (
                  <option key={fk} value={fk}>{fl}</option>
                ))}
              </select>
              <span className="text-[10px] text-muted-foreground">=</span>
              <input
                value={String(v)}
                onChange={e => updateCondition(k, k, e.target.value)}
                placeholder="valore"
                className="w-24 rounded border bg-background px-1.5 py-1 text-[10px] outline-none focus:border-emerald-500"
              />
              <button onClick={() => removeCondition(k)} className="p-0.5 text-red-400 hover:text-red-600">
                <X className="h-3 w-3" />
              </button>
            </div>
          ))}
        </div>
      )}

      {!isElse && (
        <Button variant="outline" size="sm" onClick={addCondition} className="h-6 text-[10px] px-2 w-full">
          <Plus className="h-3 w-3 mr-1" /> Aggiungi condizione (AND)
        </Button>
      )}

      {/* Then */}
      <div>
        <label className="text-[10px] text-muted-foreground">Allora vai a</label>
        <select
          value={transition.then}
          onChange={e => onChange({ then: e.target.value })}
          className="w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
        >
          {allTargets.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
      </div>
    </div>
  )
}

/* ═════════════════════════════════════════════════════════════════
 *  Side panel: edit selected node
 * ═════════════════════════════════════════════════════════════════ */

type EditTab = 'general' | 'buttons' | 'rules' | 'transitions'

function NodeEditPanel({
  node, allNodes, meta, onClose, onChange, onDelete, saving,
}: {
  node: FlowStateNode
  allNodes: FlowStateNode[]
  meta: MetaResponse | null
  onClose: () => void
  onChange: (updated: FlowStateNode) => void
  onDelete: () => void
  saving: boolean
}) {
  const isComplex = node.type === 'complex' && !node.is_custom
  const [tab, setTab] = useState<EditTab>('general')
  const allTargets = useMemo(
    () => [...new Set([...allNodes.map(n => n.state), ...(meta?.built_in ?? [])])].sort(),
    [allNodes, meta],
  )

  const updateButton = (i: number, patch: Partial<FlowButton>) => {
    onChange({
      ...node,
      buttons: node.buttons.map((b, idx) => idx === i ? { ...b, ...patch } : b),
    })
  }

  const addButton = () => {
    if (node.buttons.length >= 3) return
    onChange({
      ...node,
      buttons: [...node.buttons, { label: '', target_state: 'MENU' }],
    })
  }

  const removeButton = (i: number) => {
    onChange({ ...node, buttons: node.buttons.filter((_, idx) => idx !== i) })
  }

  /* ── Rules helpers ── */
  const updateRule = (i: number, patch: Partial<InputRule>) => {
    onChange({
      ...node,
      input_rules: node.input_rules.map((r, idx) => idx === i ? { ...r, ...patch } : r),
    })
  }
  const removeRule = (i: number) => {
    onChange({ ...node, input_rules: node.input_rules.filter((_, idx) => idx !== i) })
  }
  const addRule = (type: RuleType) => {
    const base: InputRule = { type, error_key: undefined, next_state: undefined }
    if (type === 'integer_range') { base.min = 0; base.max = 100 }
    if (type === 'mapping') base.options = ['valore: sinonimo1, sinonimo2']
    if (type === 'regex') { base.pattern = '.*'; base.capture_group = 0 }
    onChange({ ...node, input_rules: [...node.input_rules, base] })
  }

  /* ── Transitions helpers ── */
  const updateTransition = (i: number, patch: Partial<Transition>) => {
    onChange({
      ...node,
      transitions: node.transitions.map((t, idx) => idx === i ? { ...t, ...patch } : t),
    })
  }
  const removeTransition = (i: number) => {
    onChange({ ...node, transitions: node.transitions.filter((_, idx) => idx !== i) })
  }
  const addTransition = (asElse: boolean) => {
    const next: Transition = asElse
      ? { then: 'MENU' }
      : { if: { 'data.booking_type': '' }, then: 'MENU' }
    onChange({ ...node, transitions: [...node.transitions, next] })
  }

  const tabBadge = (n: number) => n > 0 ? (
    <span className="ml-1 inline-flex items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-[9px] font-bold w-4 h-4">{n}</span>
  ) : null

  return (
    <div className="fixed top-0 right-0 z-40 h-full w-[420px] border-l bg-background shadow-2xl overflow-y-auto">
      {/* Header */}
      <div className="sticky top-0 bg-background border-b px-4 py-3 flex items-center justify-between z-10">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <code className="text-sm font-mono font-bold">{node.state}</code>
            {node.is_custom && (
              <Badge variant="secondary" className="bg-teal-100 text-teal-700 text-[10px]">
                custom
              </Badge>
            )}
            {isComplex && (
              <Badge variant="outline" className="border-amber-300 text-amber-700 text-[10px]">
                logica nel codice
              </Badge>
            )}
          </div>
          {node.description && (
            <p className="text-xs text-muted-foreground mt-0.5 truncate">{node.description}</p>
          )}
        </div>
        <button onClick={onClose} className="text-muted-foreground hover:text-foreground p-1">
          <X className="h-4 w-4" />
        </button>
      </div>

      {/* Tabs */}
      <div className="sticky top-[57px] bg-background border-b z-10 px-2 flex gap-0.5">
        <TabButton active={tab === 'general'} onClick={() => setTab('general')}>
          <FileText className="h-3 w-3" /> Generale
        </TabButton>
        <TabButton active={tab === 'buttons'} onClick={() => setTab('buttons')}>
          <MousePointerClick className="h-3 w-3" /> Bottoni{tabBadge(node.buttons.length)}
        </TabButton>
        <TabButton active={tab === 'rules'} onClick={() => setTab('rules')}>
          <Filter className="h-3 w-3" /> Validazione{tabBadge(node.input_rules.length)}
        </TabButton>
        <TabButton active={tab === 'transitions'} onClick={() => setTab('transitions')}>
          <GitBranch className="h-3 w-3" /> Fork{tabBadge(node.transitions.length)}
        </TabButton>
      </div>

      <div className="p-4 space-y-5">
        {/* ── TAB: GENERAL ────────────────────────────── */}
        {tab === 'general' && (
          <>
            <div>
              <label className="text-xs font-medium text-muted-foreground">Descrizione</label>
              <textarea
                value={node.description ?? ''}
                onChange={e => onChange({ ...node, description: e.target.value })}
                placeholder="Cosa fa questo stato..."
                rows={2}
                className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500"
              />
            </div>

            {node.is_custom && (
              <div>
                <label className="text-xs font-medium text-muted-foreground">Categoria</label>
                <select
                  value={node.category}
                  onChange={e => onChange({ ...node, category: e.target.value })}
                  className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500"
                >
                  {(meta?.categories ?? []).map(c => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </div>
            )}

            <div>
              <label className="text-xs font-medium text-muted-foreground flex items-center gap-1">
                <MessageSquareText className="h-3 w-3" /> Messaggio principale
              </label>
              <select
                value={node.message_key}
                onChange={e => onChange({ ...node, message_key: e.target.value })}
                className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500 font-mono"
              >
                {(meta?.messages ?? []).map(m => (
                  <option key={m.key} value={m.key}>[{m.category}] {m.key}</option>
                ))}
              </select>
              {node.message_text && (
                <div className="mt-2 text-xs bg-muted/50 rounded p-2 whitespace-pre-line border">
                  {node.message_text}
                </div>
              )}
            </div>

            <div>
              <label className="text-xs font-medium text-muted-foreground">Messaggio fallback (input non capito)</label>
              <select
                value={node.fallback_key ?? ''}
                onChange={e => onChange({ ...node, fallback_key: e.target.value || null })}
                className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500 font-mono"
              >
                <option value="">— nessuno —</option>
                {(meta?.messages ?? []).map(m => (
                  <option key={m.key} value={m.key}>[{m.category}] {m.key}</option>
                ))}
              </select>
            </div>

            {node.is_custom && (
              <div className="pt-3 border-t">
                <Button
                  variant="outline"
                  onClick={onDelete}
                  disabled={saving}
                  className="w-full text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200"
                >
                  <Trash2 className="h-3 w-3 mr-1.5" /> Elimina stato
                </Button>
              </div>
            )}
          </>
        )}

        {/* ── TAB: BUTTONS ───────────────────────────── */}
        {tab === 'buttons' && (
          <>
            <div className="flex items-center justify-between mb-2">
              <p className="text-xs text-muted-foreground">
                Pulsanti WhatsApp ({node.buttons.length}/3)
              </p>
              {node.buttons.length < 3 && (
                <Button size="sm" variant="outline" onClick={addButton} className="h-6 text-[10px] px-2">
                  <Plus className="h-3 w-3 mr-1" /> Aggiungi
                </Button>
              )}
            </div>

            {isComplex && (
              <div className="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-2 flex gap-1.5">
                <AlertTriangle className="h-3 w-3 shrink-0 mt-0.5" />
                Stato con logica nel codice. Le label sono editabili ma le transizioni base sono nel PHP.
              </div>
            )}

            <div className="space-y-3">
              {node.buttons.map((btn, i) => (
                <div key={i} className="rounded border bg-muted/30 p-2 space-y-1.5">
                  <div className="flex items-start gap-2">
                    <input
                      value={btn.label}
                      onChange={e => updateButton(i, { label: e.target.value })}
                      maxLength={20}
                      placeholder="Label (max 20)"
                      className="flex-1 rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
                    />
                    <button onClick={() => removeButton(i)} className="p-1 text-red-400 hover:text-red-600">
                      <X className="h-3 w-3" />
                    </button>
                  </div>

                  <div>
                    <span className="text-[10px] text-muted-foreground">Vai allo stato</span>
                    <select
                      value={btn.target_state}
                      onChange={e => updateButton(i, { target_state: e.target.value })}
                      className="mt-0.5 w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500 font-mono"
                    >
                      {allTargets.map(t => <option key={t} value={t}>{t}</option>)}
                    </select>
                  </div>

                  <div>
                    <span className="text-[10px] text-muted-foreground">Side effect (opzionale)</span>
                    <select
                      value={btn.side_effect ?? ''}
                      onChange={e => updateButton(i, { side_effect: e.target.value || undefined })}
                      className="mt-0.5 w-full rounded border bg-background px-2 py-1 text-xs outline-none focus:border-emerald-500"
                    >
                      <option value="">— nessuno —</option>
                      {Object.entries(meta?.side_effects ?? {}).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                      ))}
                    </select>
                  </div>
                </div>
              ))}
              {node.buttons.length === 0 && (
                <p className="text-[10px] text-muted-foreground italic">
                  Nessun pulsante. L'utente deve rispondere con testo libero — vai al tab Validazione.
                </p>
              )}
            </div>
          </>
        )}

        {/* ── TAB: RULES ─────────────────────────────── */}
        {tab === 'rules' && (
          <>
            <p className="text-[10px] text-muted-foreground mb-2">
              Le regole validano l'input quando l'utente scrive testo libero. Vengono valutate in ordine: la prima che matcha vince.
            </p>

            <div className="space-y-3">
              {node.input_rules.map((rule, i) => (
                <RuleCard
                  key={i}
                  rule={rule}
                  index={i}
                  allTargets={allTargets}
                  meta={meta}
                  onChange={(patch) => updateRule(i, patch)}
                  onRemove={() => removeRule(i)}
                />
              ))}
            </div>

            <div className="pt-2">
              <p className="text-[10px] text-muted-foreground mb-1">Aggiungi una regola di tipo:</p>
              <div className="grid grid-cols-2 gap-1.5">
                {(['name', 'integer_range', 'mapping', 'regex', 'free_text'] as RuleType[]).map(t => {
                  const Icon = ruleTypeIcons[t]
                  return (
                    <button
                      key={t}
                      onClick={() => addRule(t)}
                      className="flex items-center gap-1.5 rounded border bg-background px-2 py-1.5 text-[10px] hover:bg-muted transition"
                    >
                      <Icon className="h-3 w-3 text-emerald-700" />
                      <span className="truncate">{meta?.rule_types[t]?.label ?? t}</span>
                    </button>
                  )
                })}
              </div>
            </div>
          </>
        )}

        {/* ── TAB: TRANSITIONS ───────────────────────── */}
        {tab === 'transitions' && (
          <>
            <p className="text-[10px] text-muted-foreground mb-2">
              Fork condizionali sui dati della sessione, valutati DOPO bottoni e regole. Es. "se l'utente è tesserato FIT vai a X, altrimenti Y".
            </p>

            <div className="space-y-3">
              {node.transitions.map((tr, i) => (
                <TransitionCard
                  key={i}
                  transition={tr}
                  index={i}
                  allTargets={allTargets}
                  meta={meta}
                  onChange={(patch) => updateTransition(i, patch)}
                  onRemove={() => removeTransition(i)}
                />
              ))}
            </div>

            <div className="pt-2 flex gap-1.5">
              <Button variant="outline" size="sm" onClick={() => addTransition(false)} className="flex-1 h-7 text-[10px]">
                <Plus className="h-3 w-3 mr-1" /> Se condizione...
              </Button>
              <Button variant="outline" size="sm" onClick={() => addTransition(true)} className="flex-1 h-7 text-[10px]">
                <Plus className="h-3 w-3 mr-1" /> Altrimenti...
              </Button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-1 px-3 py-2 text-[11px] font-medium border-b-2 transition ${
        active
          ? 'border-emerald-500 text-emerald-700'
          : 'border-transparent text-muted-foreground hover:text-foreground'
      }`}
    >
      {children}
    </button>
  )
}

/* ═════════════════════════════════════════════════════════════════
 *  Add new state dialog
 * ═════════════════════════════════════════════════════════════════ */

function AddStateDialog({
  open, onClose, onCreate, existingStates, meta,
}: {
  open: boolean
  onClose: () => void
  onCreate: (data: { state: string; message_key: string; description?: string; category?: string }) => Promise<void>
  existingStates: string[]
  meta: MetaResponse | null
}) {
  const [stateName, setStateName] = useState('')
  const [messageKey, setMessageKey] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState('custom')
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  if (!open) return null

  const validate = (): string | null => {
    const trimmed = stateName.trim().toUpperCase()
    if (!trimmed) return 'Inserisci un nome.'
    if (!/^[A-Z][A-Z0-9_]*$/.test(trimmed)) return 'Solo lettere maiuscole, cifre e underscore. Inizia con una lettera.'
    if (trimmed.length > 30) return 'Massimo 30 caratteri.'
    if (existingStates.includes(trimmed)) return 'Questo stato esiste già.'
    if (meta?.built_in.includes(trimmed)) return 'Questo nome è riservato a uno stato built-in.'
    if (!messageKey) return 'Scegli un messaggio.'
    return null
  }

  const submit = async () => {
    const err = validate()
    if (err) { setError(err); return }
    setError(null)
    setCreating(true)
    try {
      await onCreate({
        state: stateName.trim().toUpperCase(),
        message_key: messageKey,
        description: description.trim() || undefined,
        category,
      })
      setStateName('')
      setMessageKey('')
      setDescription('')
      setCategory('custom')
      onClose()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Errore sconosciuto')
    } finally {
      setCreating(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-background rounded-lg shadow-2xl w-[460px] max-w-[90vw] p-5 space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-bold flex items-center gap-2">
            <Zap className="h-4 w-4 text-teal-600" /> Nuovo stato custom
          </h2>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground p-1">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Nome stato</label>
          <input
            value={stateName}
            onChange={e => setStateName(e.target.value.toUpperCase())}
            placeholder="ES. PROMO_QUIZ"
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-sm outline-none focus:border-emerald-500 font-mono"
          />
          <p className="text-[10px] text-muted-foreground mt-1">
            Solo MAIUSCOLE, cifre e _. Es. <code>PROMO_QUIZ</code>, <code>STATO_X</code>
          </p>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Messaggio principale</label>
          <select
            value={messageKey}
            onChange={e => setMessageKey(e.target.value)}
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500 font-mono"
          >
            <option value="">— scegli un messaggio —</option>
            {(meta?.messages ?? []).map(m => (
              <option key={m.key} value={m.key}>[{m.category}] {m.key}</option>
            ))}
          </select>
          <p className="text-[10px] text-muted-foreground mt-1">
            I messaggi si gestiscono nella pagina <a href="/panel/messaggi" className="underline">Messaggi</a>.
          </p>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Categoria</label>
          <select
            value={category}
            onChange={e => setCategory(e.target.value)}
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500"
          >
            {(meta?.categories ?? []).map(c => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Descrizione</label>
          <input
            value={description}
            onChange={e => setDescription(e.target.value)}
            placeholder="A cosa serve questo stato..."
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500"
          />
        </div>

        {error && (
          <div className="text-xs text-red-700 bg-red-50 border border-red-200 rounded p-2 flex gap-1.5">
            <AlertTriangle className="h-3 w-3 shrink-0 mt-0.5" />
            {error}
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="outline" onClick={onClose} disabled={creating}>
            Annulla
          </Button>
          <Button onClick={submit} disabled={creating} className="bg-teal-600 hover:bg-teal-700">
            {creating ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <Plus className="h-3 w-3 mr-1" />}
            Crea stato
          </Button>
        </div>
      </div>
    </div>
  )
}

/* ═════════════════════════════════════════════════════════════════
 *  Main editor (inside ReactFlowProvider)
 * ═════════════════════════════════════════════════════════════════ */

function FlowEditor() {
  const [graph, setGraph] = useState<GraphResponse | null>(null)
  const [meta, setMeta] = useState<MetaResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [nodes, setNodes] = useState<Node[]>([])
  const [edges, setEdges] = useState<Edge[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [showCodeEdges, setShowCodeEdges] = useState(true)
  const [showAddDialog, setShowAddDialog] = useState(false)
  const [search, setSearch] = useState('')
  const [saving, setSaving] = useState(false)
  const [savedFlash, setSavedFlash] = useState(false)
  const [dirtyNodes, setDirtyNodes] = useState<Set<string>>(new Set())
  const [editedData, setEditedData] = useState<Map<string, FlowStateNode>>(new Map())
  const dirtyPositions = useRef<Map<string, { x: number; y: number }>>(new Map())
  const { fitView } = useReactFlow()

  /* ── Fetch graph + meta ───────────────────────────────────────── */

  const fetchAll = useCallback(async () => {
    try {
      const [g, m] = await Promise.all([
        apiFetch<GraphResponse>('/admin/bot-flow-states/graph'),
        apiFetch<MetaResponse>('/admin/bot-flow-states/meta'),
      ])
      setGraph(g)
      setMeta(m)
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchAll() }, [fetchAll])

  /* ── Build nodes/edges from graph ─────────────────────────────── */

  useEffect(() => {
    if (!graph) return

    const rfNodes: Node[] = graph.nodes.map(n => ({
      id: n.id,
      type: 'stateNode',
      position: n.position ?? { x: 0, y: 0 },
      data: n as unknown as Record<string, unknown>,
    }))

    // Auto-layout solo se almeno uno stato non ha posizione
    const needsLayout = graph.nodes.some(n => !n.position)
    const allEdgesForLayout: Edge[] = graph.buttonEdges.map(e => ({
      id: e.id, source: e.source, target: e.target,
    }))
    const layoutedNodes = needsLayout ? autoLayout(rfNodes, allEdgesForLayout) : rfNodes

    const rfEdges: Edge[] = []
    for (const e of graph.buttonEdges) {
      // Color by kind: button=verde, rule=ciano, transition=violetto
      const color = e.kind === 'rule'
        ? '#0891b2'
        : e.kind === 'transition'
          ? '#a855f7'
          : (e.side_effect ? '#f59e0b' : '#10b981')

      rfEdges.push({
        id: e.id,
        source: e.source,
        target: e.target,
        label: e.label,
        type: 'smoothstep',
        animated: !!e.side_effect || e.kind === 'transition',
        style: { stroke: color, strokeWidth: 2 },
        labelStyle: { fontSize: 10, fontWeight: 600 },
        labelBgStyle: { fill: 'white', fillOpacity: 0.9 },
        markerEnd: { type: MarkerType.ArrowClosed, color },
        data: { kind: e.kind, side_effect: e.side_effect },
      })
    }
    if (showCodeEdges) {
      for (const e of graph.codeEdges) {
        rfEdges.push({
          id: e.id,
          source: e.source,
          target: e.target,
          type: 'smoothstep',
          style: { stroke: '#94a3b8', strokeWidth: 1, strokeDasharray: '4 4' },
          markerEnd: { type: MarkerType.ArrowClosed, color: '#94a3b8' },
          data: { kind: 'code' },
        })
      }
    }

    setNodes(layoutedNodes)
    setEdges(rfEdges)

    // Se servito autolayout, segna tutti i nodi come "posizione da salvare"
    if (needsLayout) {
      layoutedNodes.forEach(n => {
        dirtyPositions.current.set(n.id, n.position)
      })
    }

    setTimeout(() => fitView({ padding: 0.2, duration: 400 }), 100)
  }, [graph, showCodeEdges, fitView])

  /* ── Node/edge change handlers ────────────────────────────────── */

  const onNodesChange = useCallback((changes: NodeChange[]) => {
    setNodes(nds => applyNodeChanges(changes, nds))
    // Track position changes per drag
    for (const c of changes) {
      if (c.type === 'position' && c.position && !c.dragging) {
        dirtyPositions.current.set(c.id, c.position)
      }
    }
  }, [])

  const onEdgesChange = useCallback((changes: EdgeChange[]) => {
    setEdges(eds => applyEdgeChanges(changes, eds))
  }, [])

  /* ── Selezione nodo ───────────────────────────────────────────── */

  const selectedNode = useMemo<FlowStateNode | null>(() => {
    if (!selectedId || !graph) return null
    const fromEdited = editedData.get(selectedId)
    if (fromEdited) return fromEdited
    return graph.nodes.find(n => n.id === selectedId) ?? null
  }, [selectedId, graph, editedData])

  const handleNodeChange = useCallback((updated: FlowStateNode) => {
    setEditedData(prev => {
      const next = new Map(prev)
      next.set(updated.id, updated)
      return next
    })
    setDirtyNodes(prev => new Set(prev).add(updated.id))

    // Aggiorna live anche il nodo sul canvas
    setNodes(nds => nds.map(n => n.id === updated.id ? { ...n, data: updated as unknown as Record<string, unknown> } : n))
  }, [])

  /* ── Save all ─────────────────────────────────────────────────── */

  const saveAll = useCallback(async () => {
    setSaving(true)
    try {
      // 1) Save dirty nodes
      const updates: Promise<unknown>[] = []
      for (const id of dirtyNodes) {
        const data = editedData.get(id)
        if (!data) continue
        updates.push(
          apiFetch(`/admin/bot-flow-states/${id}`, {
            method: 'PUT',
            body: JSON.stringify({
              message_key: data.message_key,
              fallback_key: data.fallback_key,
              description: data.description,
              category: data.is_custom ? data.category : undefined,
              buttons: data.buttons,
              input_rules: data.input_rules.length > 0 ? data.input_rules : null,
              transitions: data.transitions.length > 0 ? data.transitions : null,
            }),
          }),
        )
      }

      // 2) Save dirty positions in bulk
      if (dirtyPositions.current.size > 0) {
        const positions = Array.from(dirtyPositions.current.entries()).map(([state, position]) => ({
          state, position,
        }))
        updates.push(
          apiFetch('/admin/bot-flow-states/positions', {
            method: 'PUT',
            body: JSON.stringify({ positions }),
          }),
        )
      }

      await Promise.all(updates)

      dirtyPositions.current.clear()
      setDirtyNodes(new Set())
      setEditedData(new Map())
      setSavedFlash(true)
      setTimeout(() => setSavedFlash(false), 2500)

      // Refresh per riallineare cache backend
      await fetchAll()
    } catch (e) {
      console.error('Save failed', e)
      alert('Errore durante il salvataggio. Controlla la console.')
    } finally {
      setSaving(false)
    }
  }, [dirtyNodes, editedData, fetchAll])

  /* ── Create new state ─────────────────────────────────────────── */

  const handleCreate = useCallback(async (data: { state: string; message_key: string; description?: string; category?: string }) => {
    const created = await apiFetch<FlowStateNode>('/admin/bot-flow-states', {
      method: 'POST',
      body: JSON.stringify({
        ...data,
        position: { x: 200, y: 200 },
        buttons: [],
      }),
    })
    await fetchAll()
    setSelectedId(created.state)
  }, [fetchAll])

  /* ── Delete selected ──────────────────────────────────────────── */

  const handleDelete = useCallback(async () => {
    if (!selectedNode) return
    if (!confirm(`Eliminare lo stato ${selectedNode.state}?`)) return
    try {
      await apiFetch(`/admin/bot-flow-states/${selectedNode.state}`, { method: 'DELETE' })
      setSelectedId(null)
      setEditedData(prev => {
        const next = new Map(prev)
        next.delete(selectedNode.state)
        return next
      })
      setDirtyNodes(prev => {
        const next = new Set(prev)
        next.delete(selectedNode.state)
        return next
      })
      await fetchAll()
    } catch (e) {
      const msg = e instanceof Error ? e.message : 'Errore sconosciuto'
      alert(`Eliminazione fallita: ${msg}`)
    }
  }, [selectedNode, fetchAll])

  /* ── Connection (drag handle to handle = create new button) ─── */

  const onConnect = useCallback((conn: Connection) => {
    if (!conn.source || !conn.target || !graph) return
    const sourceNode = (editedData.get(conn.source) ?? graph.nodes.find(n => n.id === conn.source))
    if (!sourceNode) return
    if (sourceNode.buttons.length >= 3) {
      alert('Massimo 3 bottoni per stato.')
      return
    }
    const newButton: FlowButton = {
      label: 'Nuovo bottone',
      target_state: conn.target,
    }
    handleNodeChange({
      ...sourceNode,
      buttons: [...sourceNode.buttons, newButton],
    })
    setSelectedId(conn.source)
  }, [graph, editedData, handleNodeChange])

  /* ── Search filter ────────────────────────────────────────────── */

  const filteredNodes = useMemo(() => {
    if (!search.trim()) return nodes
    const q = search.toLowerCase()
    const matchingIds = new Set(
      (graph?.nodes ?? [])
        .filter(n =>
          n.state.toLowerCase().includes(q) ||
          n.description?.toLowerCase().includes(q) ||
          n.message_text?.toLowerCase().includes(q) ||
          n.buttons.some(b => b.label.toLowerCase().includes(q)),
        )
        .map(n => n.id),
    )
    return nodes.map(n => ({
      ...n,
      style: { ...n.style, opacity: matchingIds.has(n.id) ? 1 : 0.25 },
    }))
  }, [nodes, search, graph])

  /* ── Render ───────────────────────────────────────────────────── */

  if (loading) {
    return (
      <div className="flex justify-center py-24">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const dirtyCount = dirtyNodes.size + dirtyPositions.current.size
  const hasChanges = dirtyCount > 0

  return (
    <div className="relative w-full" style={{ height: 'calc(100vh - 130px)' }}>
      <ReactFlow
        nodes={filteredNodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={onConnect}
        onNodeClick={(_, n) => setSelectedId(n.id)}
        onPaneClick={() => setSelectedId(null)}
        nodeTypes={nodeTypes}
        fitView
        minZoom={0.2}
        maxZoom={2}
        proOptions={{ hideAttribution: true }}
      >
        <Background gap={20} size={1} color="#e5e7eb" />
        <Controls position="bottom-left" />
        <MiniMap
          position="bottom-right"
          nodeColor={(n) => {
            const data = n.data as unknown as FlowNodeData
            return getCategoryColor(data?.category ?? 'errore').border.replace('border-', '#').replace('-400', '')
          }}
          maskColor="rgba(0,0,0,0.04)"
        />

        {/* ── Toolbar top ──────────────────────────────── */}
        <Panel position="top-left" className="!m-3">
          <div className="bg-background border rounded-lg shadow-sm p-2 flex items-center gap-2">
            <h1 className="text-sm font-bold pl-1">Flusso Bot</h1>
            <div className="h-5 w-px bg-border" />
            <Button
              size="sm"
              onClick={() => setShowAddDialog(true)}
              className="bg-teal-600 hover:bg-teal-700 h-7 text-xs"
            >
              <Plus className="h-3 w-3 mr-1" /> Nuovo stato
            </Button>
            <Button
              size="sm"
              variant={hasChanges ? 'default' : 'outline'}
              onClick={saveAll}
              disabled={!hasChanges || saving}
              className={`h-7 text-xs ${hasChanges ? 'bg-emerald-600 hover:bg-emerald-700' : ''}`}
            >
              {saving
                ? <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                : savedFlash
                  ? <Check className="h-3 w-3 mr-1" />
                  : <Save className="h-3 w-3 mr-1" />
              }
              {savedFlash ? 'Salvato!' : hasChanges ? `Salva (${dirtyCount})` : 'Salva'}
            </Button>
          </div>
        </Panel>

        {/* ── Toolbar top right: search + toggle code edges ── */}
        <Panel position="top-right" className="!m-3">
          <div className="bg-background border rounded-lg shadow-sm p-2 flex items-center gap-2">
            <div className="relative">
              <Search className="absolute left-2 top-1/2 h-3 w-3 -translate-y-1/2 text-muted-foreground" />
              <input
                type="text"
                placeholder="Cerca stati..."
                value={search}
                onChange={e => setSearch(e.target.value)}
                className="pl-7 pr-2 py-1 text-xs rounded border bg-background outline-none focus:border-emerald-500 w-40"
              />
            </div>
            <label className="flex items-center gap-1 text-xs cursor-pointer select-none">
              <input
                type="checkbox"
                checked={showCodeEdges}
                onChange={e => setShowCodeEdges(e.target.checked)}
                className="cursor-pointer"
              />
              <span>Transizioni codice</span>
            </label>
          </div>
        </Panel>

        {/* ── Legenda ─────────────────────────────────── */}
        <Panel position="bottom-center" className="!m-3">
          <div className="bg-background border rounded-lg shadow-sm px-3 py-1.5 flex items-center gap-3 text-[10px] flex-wrap">
            <div className="flex items-center gap-1">
              <div className="w-4 h-px bg-emerald-500" />
              <span>Bottone</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-px bg-amber-500" />
              <span>Side-effect</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-px bg-cyan-600" />
              <span>Validazione</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-px bg-purple-500" />
              <span>Fork condizionale</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-px border-t border-dashed border-gray-400" />
              <span>Codice</span>
            </div>
            <div className="h-3 w-px bg-border" />
            <span className="flex items-center gap-1"><Cog className="h-2.5 w-2.5" /> Simple</span>
            <span className="flex items-center gap-1"><Cpu className="h-2.5 w-2.5 text-amber-600" /> Complex</span>
            <span className="flex items-center gap-1"><Zap className="h-2.5 w-2.5 text-teal-600" /> Custom</span>
          </div>
        </Panel>
      </ReactFlow>

      {/* ── Side panel ─────────────────────────────────── */}
      {selectedNode && (
        <NodeEditPanel
          node={selectedNode}
          allNodes={graph?.nodes ?? []}
          meta={meta}
          onClose={() => setSelectedId(null)}
          onChange={handleNodeChange}
          onDelete={handleDelete}
          saving={saving}
        />
      )}

      {/* ── Add dialog ─────────────────────────────────── */}
      <AddStateDialog
        open={showAddDialog}
        onClose={() => setShowAddDialog(false)}
        onCreate={handleCreate}
        existingStates={graph?.nodes.map(n => n.state) ?? []}
        meta={meta}
      />
    </div>
  )
}

/* ═════════════════════════════════════════════════════════════════
 *  Outer wrapper with provider
 * ═════════════════════════════════════════════════════════════════ */

export function Flusso() {
  return (
    <ReactFlowProvider>
      <FlowEditor />
    </ReactFlowProvider>
  )
}
