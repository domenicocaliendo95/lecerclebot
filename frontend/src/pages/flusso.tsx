import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  Controls,
  Handle,
  Position,
  MarkerType,
  type Node,
  type Edge,
  type NodeProps,
  useReactFlow,
} from '@xyflow/react'
import dagre from '@dagrejs/dagre'
import {
  Loader2, Save, Plus, Trash2, X, Cpu, Cog, MessageSquareText,
  Zap, AlertTriangle, Check, Search, MousePointerClick,
  Filter, GitBranch, Type, Hash, ListChecks, Code, FileText, Pencil,
  ArrowDown, CornerDownRight, Circle,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { apiFetch } from '@/hooks/use-api'

import '@xyflow/react/dist/style.css'

/* =================================================================
 *  Types
 * ================================================================= */

interface FlowButton {
  label: string
  target_state: string
  value?: string
  side_effect?: string
}

type RuleType = 'name' | 'integer_range' | 'mapping' | 'regex' | 'free_text'

interface InputRule {
  type: RuleType
  save_to?: string
  next_state?: string
  error_key?: string
  side_effect?: string
  transform?: string
  min?: number
  max?: number
  options?: string[]
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
  on_enter_actions: string[]
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

interface ActionMeta {
  label: string
  description: string
  timing: 'pre' | 'post'
}

interface MetaResponse {
  side_effects: Record<string, string>
  actions: Record<string, ActionMeta>
  pre_actions: Record<string, ActionMeta>
  post_actions: Record<string, ActionMeta>
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

type FlowNodeData = FlowStateNode & { isTrigger?: boolean; isGotoRef?: boolean; gotoTarget?: string }

/* =================================================================
 *  Color helpers
 * ================================================================= */

const categoryColors: Record<string, { bg: string; border: string; text: string; headerBg: string }> = {
  onboarding:   { bg: 'bg-blue-50',    border: 'border-blue-300',    text: 'text-blue-700',    headerBg: 'bg-blue-500' },
  menu:         { bg: 'bg-emerald-50', border: 'border-emerald-300', text: 'text-emerald-700', headerBg: 'bg-emerald-500' },
  prenotazione: { bg: 'bg-amber-50',   border: 'border-amber-300',   text: 'text-amber-700',   headerBg: 'bg-amber-500' },
  conferma:     { bg: 'bg-purple-50',  border: 'border-purple-300',  text: 'text-purple-700',  headerBg: 'bg-purple-500' },
  matchmaking:  { bg: 'bg-orange-50',  border: 'border-orange-300',  text: 'text-orange-700',  headerBg: 'bg-orange-500' },
  gestione:     { bg: 'bg-cyan-50',    border: 'border-cyan-300',    text: 'text-cyan-700',    headerBg: 'bg-cyan-500' },
  profilo:      { bg: 'bg-pink-50',    border: 'border-pink-300',    text: 'text-pink-700',    headerBg: 'bg-pink-500' },
  risultati:    { bg: 'bg-red-50',     border: 'border-red-300',     text: 'text-red-700',     headerBg: 'bg-red-500' },
  feedback:     { bg: 'bg-yellow-50',  border: 'border-yellow-300',  text: 'text-yellow-700',  headerBg: 'bg-yellow-500' },
  avversario:   { bg: 'bg-violet-50',  border: 'border-violet-300',  text: 'text-violet-700',  headerBg: 'bg-violet-500' },
  errore:       { bg: 'bg-gray-100',   border: 'border-gray-300',    text: 'text-gray-700',    headerBg: 'bg-gray-500' },
  custom:       { bg: 'bg-teal-50',    border: 'border-teal-300',    text: 'text-teal-700',    headerBg: 'bg-teal-500' },
}

function getCategoryColor(category: string) {
  return categoryColors[category] ?? categoryColors.errore
}

/* =================================================================
 *  Highlight {variables} in message text
 * ================================================================= */

function HighlightedText({ text, maxLen }: { text: string; maxLen: number }) {
  const truncated = text.length > maxLen ? text.slice(0, maxLen - 1) + '\u2026' : text
  const parts = truncated.split(/(\{[a-z_]+\})/g)
  return (
    <span>
      {parts.map((part, i) =>
        /^\{[a-z_]+\}$/.test(part)
          ? <code key={i} className="rounded bg-amber-100 text-amber-800 px-0.5 text-[10px] font-mono">{part}</code>
          : <span key={i}>{part}</span>
      )}
    </span>
  )
}

/* =================================================================
 *  Custom node: Shopify Flow style card
 * ================================================================= */

function FlowCard({ data }: NodeProps) {
  const node = data as unknown as FlowNodeData
  const colors = getCategoryColor(node.category)

  // Trigger node
  if (node.isTrigger) {
    return (
      <div
        className="rounded-xl border-2 border-green-400 bg-green-50 shadow-md cursor-pointer hover:shadow-lg transition-shadow"
        style={{ width: 380 }}
      >
        <Handle type="target" position={Position.Top} className="!bg-transparent !border-0 !w-0 !h-0" />
        <div className="bg-green-500 rounded-t-[10px] px-4 py-2 flex items-center gap-2">
          <Circle className="h-3 w-3 text-white fill-white" />
          <span className="text-xs font-bold text-white tracking-wide uppercase">Punto di ingresso</span>
        </div>
        <div className="px-4 py-3">
          <code className="text-sm font-mono font-bold text-green-800">{node.state}</code>
          {node.message_text && (
            <p className="text-xs text-green-700 mt-1.5 leading-snug line-clamp-2">
              <HighlightedText text={node.message_text} maxLen={120} />
            </p>
          )}
        </div>
        <Handle type="source" position={Position.Bottom} className="!bg-green-500 !w-3 !h-3 !border-2 !border-white" />
      </div>
    )
  }

  // Goto reference node (back-edge placeholder)
  if (node.isGotoRef) {
    return (
      <div
        className="rounded-lg border-2 border-dashed border-gray-400 bg-gray-50 shadow-sm cursor-pointer hover:shadow-md transition-shadow"
        style={{ width: 380 }}
      >
        <Handle type="target" position={Position.Top} className="!bg-gray-400 !w-2.5 !h-2.5 !border-2 !border-white" />
        <div className="px-4 py-3 flex items-center gap-2">
          <CornerDownRight className="h-4 w-4 text-gray-500 shrink-0" />
          <div>
            <span className="text-[10px] text-gray-500 uppercase tracking-wide font-medium">Vai a</span>
            <code className="ml-1.5 text-xs font-mono font-bold text-gray-700">{node.gotoTarget}</code>
          </div>
        </div>
      </div>
    )
  }

  // Standard message card
  return (
    <div
      className={`rounded-xl border shadow-md cursor-pointer hover:shadow-lg transition-shadow ${colors.bg} ${colors.border}`}
      style={{ width: 380 }}
    >
      <Handle type="target" position={Position.Top} className="!bg-gray-400 !w-2.5 !h-2.5 !border-2 !border-white" />

      {/* Header with category color bar */}
      <div className={`${colors.headerBg} rounded-t-[10px] px-4 py-2 flex items-center justify-between gap-2`}>
        <code className="text-xs font-bold font-mono text-white truncate">{node.state}</code>
        <div className="flex items-center gap-1.5 shrink-0">
          {node.is_custom && (
            <span className="inline-flex items-center gap-0.5 rounded bg-white/30 px-1.5 py-0.5 text-[9px] font-bold text-white">
              <Zap className="h-2.5 w-2.5" /> custom
            </span>
          )}
          {node.type === 'complex' ? (
            <span className="inline-flex items-center gap-0.5 rounded bg-white/30 px-1.5 py-0.5 text-[9px] font-bold text-white">
              <Cpu className="h-2.5 w-2.5" /> complex
            </span>
          ) : (
            <span className="inline-flex items-center gap-0.5 rounded bg-white/30 px-1.5 py-0.5 text-[9px] font-bold text-white">
              <Cog className="h-2.5 w-2.5" /> simple
            </span>
          )}
          <span className="rounded bg-white/30 px-1.5 py-0.5 text-[9px] font-medium text-white">
            {node.category}
          </span>
        </div>
      </div>

      {/* Body */}
      <div className="px-4 py-3 space-y-2">
        {/* On-enter actions */}
        {node.on_enter_actions.length > 0 && (
          <div className="flex flex-wrap gap-1">
            {node.on_enter_actions.map((a, i) => (
              <span key={i} className="inline-flex items-center gap-0.5 rounded bg-amber-100 text-amber-800 px-1.5 py-0.5 text-[10px] font-medium">
                <Zap className="h-2.5 w-2.5" /> {a}
              </span>
            ))}
          </div>
        )}

        {/* Message preview */}
        {node.message_text ? (
          <div>
            <div className="flex items-center gap-1.5 mb-1">
              <MessageSquareText className="h-3 w-3 text-gray-500 shrink-0" />
              <code className="text-[10px] font-mono text-gray-500 bg-white/60 rounded px-1">{node.message_key}</code>
            </div>
            <p className="text-xs text-gray-700 leading-snug line-clamp-3">
              <HighlightedText text={node.message_text} maxLen={180} />
            </p>
          </div>
        ) : (
          <div className="flex items-center gap-1.5">
            <MessageSquareText className="h-3 w-3 text-gray-400 shrink-0" />
            <code className="text-[10px] font-mono text-gray-400">{node.message_key}</code>
          </div>
        )}

        {/* Input rules summary */}
        {node.input_rules.length > 0 && (
          <div className="flex items-center gap-1.5 text-[10px] text-cyan-700 bg-cyan-50 border border-cyan-200 rounded px-2 py-1">
            <Filter className="h-3 w-3 shrink-0" />
            <span className="font-medium">Validazione: {node.input_rules.length} {node.input_rules.length === 1 ? 'regola' : 'regole'}</span>
            <span className="text-cyan-500 ml-auto truncate">
              {node.input_rules.map(r => r.type).join(', ')}
            </span>
          </div>
        )}

        {/* Transitions summary */}
        {node.transitions.length > 0 && (
          <div className="space-y-0.5">
            {node.transitions.slice(0, 2).map((tr, i) => {
              const conditions = Object.entries(tr.if ?? {})
              return (
                <div key={i} className="flex items-center gap-1.5 text-[10px] text-purple-700 bg-purple-50 border border-purple-200 rounded px-2 py-1">
                  <GitBranch className="h-3 w-3 shrink-0" />
                  {conditions.length > 0 ? (
                    <span>
                      Se {conditions.map(([k, v]) => `${k} = ${v}`).join(' AND ')} <span className="font-mono font-bold">{'\u2192'} {tr.then}</span>
                    </span>
                  ) : (
                    <span>Altrimenti <span className="font-mono font-bold">{'\u2192'} {tr.then}</span></span>
                  )}
                </div>
              )
            })}
            {node.transitions.length > 2 && (
              <div className="text-[9px] text-purple-500 pl-5">+{node.transitions.length - 2} {node.transitions.length - 2 === 1 ? 'altra' : 'altre'} condizioni</div>
            )}
          </div>
        )}

        {/* Buttons as pills */}
        {node.buttons.length > 0 && (
          <div className="space-y-1 pt-1">
            {node.buttons.map((btn, i) => (
              <div
                key={i}
                className="flex items-center justify-between gap-2 rounded-lg bg-white border border-gray-200 px-3 py-1.5 text-[11px] shadow-sm"
              >
                <span className="font-medium text-gray-800 truncate">{btn.label || '(vuoto)'}</span>
                <div className="flex items-center gap-1.5 shrink-0">
                  {btn.side_effect && (
                    <span className="rounded bg-amber-100 text-amber-700 px-1 py-0 text-[9px] font-mono">
                      {btn.side_effect}
                    </span>
                  )}
                  <span className="font-mono text-gray-500 text-[10px]">{'\u2192'} {btn.target_state}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <Handle type="source" position={Position.Bottom} className={`!w-2.5 !h-2.5 !border-2 !border-white !bg-emerald-500`} />
    </div>
  )
}

const nodeTypes = { flowCard: FlowCard }

/* =================================================================
 *  Dagre layout - vertical, centered
 * ================================================================= */

function autoLayout(nodes: Node[], edges: Edge[]): Node[] {
  const g = new dagre.graphlib.Graph()
  g.setDefaultEdgeLabel(() => ({}))
  g.setGraph({ rankdir: 'TB', nodesep: 50, ranksep: 80, marginx: 40, marginy: 40 })

  nodes.forEach(n => g.setNode(n.id, { width: 400, height: 200 }))
  edges.forEach(e => g.setEdge(e.source, e.target))

  dagre.layout(g)

  return nodes.map(n => {
    const pos = g.node(n.id)
    return {
      ...n,
      position: { x: pos.x - 200, y: pos.y - 100 },
    }
  })
}

/* =================================================================
 *  Live tester for input rules
 * ================================================================= */

function testRule(rule: InputRule, input: string): { ok: boolean; value?: string } {
  const clean = input.trim()
  if (!clean) return { ok: false }

  switch (rule.type) {
    case 'name': {
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

/* =================================================================
 *  Sub-editor: una singola input_rule
 * ================================================================= */

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
          <option value="">-- non salvare --</option>
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
          <option value="">-- resta qui --</option>
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
          <option value="">-- default --</option>
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
          <option value="">-- nessuno --</option>
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
              ? <>Match{result.value !== undefined && <> {'\u2192'} <code className="bg-white/60 rounded px-1">{result.value}</code></>}</>
              : <>Non matcha</>}
          </p>
        )}
      </div>
    </div>
  )
}

/* =================================================================
 *  Sub-editor: una singola transition
 * ================================================================= */

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

/* =================================================================
 *  MessageEditor: dropdown key + editable textarea
 * ================================================================= */

function MessageEditor({
  label, icon, currentKey, messages, messageOverrides, originalText,
  sharedKeys, currentState, onKeyChange, onTextChange, onCreateNew, allowNone,
}: {
  label: string
  icon: React.ReactNode
  currentKey: string
  messages: { key: string; category: string; description: string | null }[]
  messageOverrides: Map<string, string>
  originalText: string | null
  sharedKeys: Map<string, string[]>
  currentState: string
  onKeyChange: (key: string) => void
  onTextChange: (text: string) => void
  onCreateNew: () => void
  allowNone: boolean
}) {
  const text = currentKey
    ? (messageOverrides.get(currentKey) ?? originalText ?? '')
    : ''
  const isDirty = currentKey && messageOverrides.has(currentKey)

  const otherUsers = currentKey
    ? (sharedKeys.get(currentKey) ?? []).filter(s => s !== currentState)
    : []

  const variables = useMemo(() => {
    if (!text) return []
    const matches = text.match(/\{[a-z_]+\}/g)
    return matches ? Array.from(new Set(matches)) : []
  }, [text])

  return (
    <div>
      <label className="text-xs font-medium text-muted-foreground flex items-center gap-1">
        {icon} {label}
        {isDirty && (
          <span className="ml-auto inline-flex items-center gap-0.5 rounded bg-amber-100 text-amber-800 px-1.5 py-0.5 text-[9px] font-bold">
            <Pencil className="h-2 w-2" /> modificato
          </span>
        )}
      </label>

      <div className="mt-1 flex gap-1">
        <select
          value={currentKey}
          onChange={e => onKeyChange(e.target.value)}
          className="flex-1 rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500 font-mono min-w-0"
        >
          {allowNone && <option value="">-- nessuno --</option>}
          {messages.map(m => (
            <option key={m.key} value={m.key}>[{m.category}] {m.key}</option>
          ))}
        </select>
        <button
          onClick={onCreateNew}
          title="Crea nuovo messaggio"
          className="rounded border bg-background hover:bg-muted px-2 py-1.5 text-xs text-emerald-700"
        >
          <Plus className="h-3 w-3" />
        </button>
      </div>

      {currentKey && (
        <>
          <textarea
            value={text}
            onChange={e => onTextChange(e.target.value)}
            rows={3}
            placeholder="Testo del messaggio..."
            className="mt-2 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500 leading-snug"
            maxLength={1000}
          />

          {/* Variable badges */}
          {variables.length > 0 && (
            <div className="mt-1 flex flex-wrap gap-1">
              <span className="text-[9px] text-muted-foreground">Variabili:</span>
              {variables.map(v => (
                <span key={v} className="rounded bg-amber-100 text-amber-800 px-1 py-0 text-[9px] font-mono">
                  {v}
                </span>
              ))}
            </div>
          )}

          {/* Shared key warning */}
          {otherUsers.length > 0 && (
            <div className="mt-1.5 text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 flex items-start gap-1">
              <AlertTriangle className="h-2.5 w-2.5 shrink-0 mt-0.5" />
              <span>
                Questo messaggio e' usato anche da: <strong className="font-mono">{otherUsers.join(', ')}</strong>. Modificarlo cambiera' il testo per tutti.
              </span>
            </div>
          )}

          {/* Char counter */}
          <div className="mt-1 text-right text-[9px] text-muted-foreground">
            {text.length}/1000
          </div>
        </>
      )}
    </div>
  )
}

/* =================================================================
 *  Side panel: edit selected node
 * ================================================================= */

type EditTab = 'general' | 'buttons' | 'rules' | 'transitions' | 'actions'

function NodeEditPanel({
  node, allNodes, meta, messageOverrides, sharedKeys, onClose, onChange,
  onMessageEdit, onCreateMessage, onDelete, saving,
}: {
  node: FlowStateNode
  allNodes: FlowStateNode[]
  meta: MetaResponse | null
  messageOverrides: Map<string, string>
  sharedKeys: Map<string, string[]>
  onClose: () => void
  onChange: (updated: FlowStateNode) => void
  onMessageEdit: (key: string, text: string) => void
  onCreateMessage: () => void
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

  /* -- Rules helpers -- */
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

  /* -- Transitions helpers -- */
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
        <TabButton active={tab === 'actions'} onClick={() => setTab('actions')}>
          <Zap className="h-3 w-3" /> Azioni{tabBadge(node.on_enter_actions.length)}
        </TabButton>
      </div>

      <div className="p-4 space-y-5">
        {/* -- TAB: GENERAL -- */}
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

            <MessageEditor
              label="Messaggio principale"
              icon={<MessageSquareText className="h-3 w-3" />}
              currentKey={node.message_key}
              messages={meta?.messages ?? []}
              messageOverrides={messageOverrides}
              originalText={node.message_text}
              sharedKeys={sharedKeys}
              currentState={node.state}
              onKeyChange={(k) => onChange({ ...node, message_key: k })}
              onTextChange={(text) => onMessageEdit(node.message_key, text)}
              onCreateNew={onCreateMessage}
              allowNone={false}
            />

            <MessageEditor
              label="Messaggio fallback (input non capito)"
              icon={<AlertTriangle className="h-3 w-3" />}
              currentKey={node.fallback_key ?? ''}
              messages={meta?.messages ?? []}
              messageOverrides={messageOverrides}
              originalText={node.fallback_text}
              sharedKeys={sharedKeys}
              currentState={node.state}
              onKeyChange={(k) => onChange({ ...node, fallback_key: k || null })}
              onTextChange={(text) => node.fallback_key && onMessageEdit(node.fallback_key, text)}
              onCreateNew={onCreateMessage}
              allowNone
            />

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

        {/* -- TAB: BUTTONS -- */}
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
                      <option value="">-- nessuno --</option>
                      {Object.entries(meta?.side_effects ?? {}).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                      ))}
                    </select>
                  </div>
                </div>
              ))}
              {node.buttons.length === 0 && (
                <p className="text-[10px] text-muted-foreground italic">
                  Nessun pulsante. L'utente deve rispondere con testo libero -- vai al tab Validazione.
                </p>
              )}
            </div>
          </>
        )}

        {/* -- TAB: RULES -- */}
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

        {/* -- TAB: TRANSITIONS -- */}
        {tab === 'transitions' && (
          <>
            <p className="text-[10px] text-muted-foreground mb-2">
              Fork condizionali sui dati della sessione, valutati DOPO bottoni e regole. Es. "se l'utente e' tesserato FIT vai a X, altrimenti Y".
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

        {/* -- TAB: ACTIONS -- */}
        {tab === 'actions' && (
          <>
            <p className="text-[10px] text-muted-foreground mb-3">
              Azioni eseguite automaticamente all'ingresso di questo stato, PRIMA di mostrare il messaggio. I risultati finiscono nella sessione e possono essere letti dai Fork.
            </p>

            {/* On-enter actions list */}
            <div className="space-y-2">
              {node.on_enter_actions.map((actionKey, i) => {
                const actionMeta = meta?.actions?.[actionKey]
                return (
                  <div key={i} className="flex items-start gap-2 rounded border bg-muted/30 p-2.5">
                    <div className="rounded bg-amber-100 p-1 mt-0.5">
                      <Zap className="h-3 w-3 text-amber-700" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-xs font-medium">{actionMeta?.label ?? actionKey}</p>
                      {actionMeta?.description && (
                        <p className="text-[10px] text-muted-foreground mt-0.5">{actionMeta.description}</p>
                      )}
                    </div>
                    <button
                      onClick={() => onChange({
                        ...node,
                        on_enter_actions: node.on_enter_actions.filter((_, idx) => idx !== i),
                      })}
                      className="p-1 text-red-400 hover:text-red-600"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </div>
                )
              })}
            </div>

            {node.on_enter_actions.length === 0 && (
              <p className="text-[10px] text-muted-foreground italic mb-2">
                Nessuna azione all'ingresso. Lo stato mostra subito il messaggio.
              </p>
            )}

            {/* Add pre-action */}
            <div className="pt-2">
              <p className="text-[10px] text-muted-foreground mb-1">Aggiungi un'azione:</p>
              <div className="space-y-1">
                {Object.entries(meta?.pre_actions ?? {}).map(([key, am]) => {
                  const alreadyAdded = node.on_enter_actions.includes(key)
                  return (
                    <button
                      key={key}
                      disabled={alreadyAdded}
                      onClick={() => onChange({
                        ...node,
                        on_enter_actions: [...node.on_enter_actions, key],
                      })}
                      className={`w-full flex items-center gap-2 rounded border px-2.5 py-2 text-left transition ${
                        alreadyAdded
                          ? 'opacity-40 cursor-not-allowed bg-muted/30'
                          : 'bg-background hover:bg-muted cursor-pointer'
                      }`}
                    >
                      <Zap className="h-3 w-3 text-amber-600 shrink-0" />
                      <div className="min-w-0">
                        <p className="text-[11px] font-medium truncate">{am.label}</p>
                        <p className="text-[9px] text-muted-foreground truncate">{am.description}</p>
                      </div>
                      {alreadyAdded && (
                        <Check className="h-3 w-3 text-emerald-500 ml-auto shrink-0" />
                      )}
                    </button>
                  )
                })}
              </div>
            </div>

            {/* Info about post-actions */}
            <div className="pt-3 border-t mt-3">
              <p className="text-[10px] text-muted-foreground">
                <strong>Le azioni post-transizione</strong> (crea prenotazione, salva profilo, ecc.) si configurano nei tab <strong>Bottoni</strong> e <strong>Validazione</strong> come "Azione" di ciascun bottone o regola.
              </p>
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

/* =================================================================
 *  Add new state dialog
 * ================================================================= */

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
    if (existingStates.includes(trimmed)) return 'Questo stato esiste gia\'.'
    if (meta?.built_in.includes(trimmed)) return 'Questo nome e\' riservato a uno stato built-in.'
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
            <option value="">-- scegli un messaggio --</option>
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

/* =================================================================
 *  Add new message dialog
 * ================================================================= */

function AddMessageDialog({
  open, onClose, onCreate, existingKeys, categories, suggestedKey,
}: {
  open: boolean
  onClose: () => void
  onCreate: (data: { key: string; text: string; category: string; description?: string }) => Promise<void>
  existingKeys: string[]
  categories: string[]
  suggestedKey: string
}) {
  const [key, setKey] = useState('')
  const [text, setText] = useState('')
  const [category, setCategory] = useState('custom')
  const [description, setDescription] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  useEffect(() => {
    if (open) {
      setKey(suggestedKey)
      setError(null)
    }
  }, [open, suggestedKey])

  if (!open) return null

  const validate = (): string | null => {
    const k = key.trim().toLowerCase()
    if (!k) return 'Inserisci una chiave.'
    if (!/^[a-z][a-z0-9_]*$/.test(k)) return 'Solo minuscole, cifre e underscore. Inizia con una lettera.'
    if (k.length > 100) return 'Massimo 100 caratteri.'
    if (existingKeys.includes(k)) return 'Questa chiave esiste gia\'.'
    if (!text.trim()) return 'Il testo del messaggio non puo\' essere vuoto.'
    return null
  }

  const submit = async () => {
    const err = validate()
    if (err) { setError(err); return }
    setError(null)
    setCreating(true)
    try {
      await onCreate({
        key: key.trim().toLowerCase(),
        text: text.trim(),
        category,
        description: description.trim() || undefined,
      })
      setKey('')
      setText('')
      setDescription('')
      setCategory('custom')
      onClose()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Errore sconosciuto')
    } finally {
      setCreating(false)
    }
  }

  const variables = useMemo(() => {
    const matches = text.match(/\{[a-z_]+\}/g)
    return matches ? Array.from(new Set(matches)) : []
  }, [text])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-background rounded-lg shadow-2xl w-[500px] max-w-[90vw] p-5 space-y-4 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-bold flex items-center gap-2">
            <MessageSquareText className="h-4 w-4 text-emerald-600" /> Nuovo messaggio
          </h2>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground p-1">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Chiave</label>
          <input
            value={key}
            onChange={e => setKey(e.target.value.toLowerCase())}
            placeholder="es. msg_promo_intro"
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-sm outline-none focus:border-emerald-500 font-mono"
          />
          <p className="text-[10px] text-muted-foreground mt-1">
            Solo minuscole, cifre e _. La chiave non si puo' cambiare dopo la creazione.
          </p>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Categoria</label>
          <select
            value={category}
            onChange={e => setCategory(e.target.value)}
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500"
          >
            {categories.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Testo del messaggio</label>
          <textarea
            value={text}
            onChange={e => setText(e.target.value)}
            rows={4}
            placeholder="Ciao {name}! Cosa vuoi fare?"
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500"
            maxLength={1000}
          />
          {variables.length > 0 && (
            <div className="mt-1 flex flex-wrap gap-1">
              <span className="text-[9px] text-muted-foreground">Variabili rilevate:</span>
              {variables.map(v => (
                <span key={v} className="rounded bg-amber-100 text-amber-800 px-1 py-0 text-[9px] font-mono">{v}</span>
              ))}
            </div>
          )}
          <p className="text-[10px] text-muted-foreground mt-1">
            Usa <code className="bg-muted px-1 rounded">{'{nome_variabile}'}</code> per inserire valori dinamici (es. <code className="bg-muted px-1 rounded">{'{name}'}</code>, <code className="bg-muted px-1 rounded">{'{slot}'}</code>).
          </p>
          <div className="text-right text-[9px] text-muted-foreground">{text.length}/1000</div>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Descrizione (interna)</label>
          <input
            value={description}
            onChange={e => setDescription(e.target.value)}
            placeholder="A cosa serve questo messaggio..."
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
          <Button onClick={submit} disabled={creating} className="bg-emerald-600 hover:bg-emerald-700">
            {creating ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <Plus className="h-3 w-3 mr-1" />}
            Crea messaggio
          </Button>
        </div>
      </div>
    </div>
  )
}

/* =================================================================
 *  Insert state dialog (between two nodes)
 * ================================================================= */

function InsertStateDialog({
  open, onClose, onCreate, existingStates, meta, sourceState, targetState,
}: {
  open: boolean
  onClose: () => void
  onCreate: (data: { state: string; message_key: string; description?: string; category?: string; sourceState: string; targetState: string }) => Promise<void>
  existingStates: string[]
  meta: MetaResponse | null
  sourceState: string
  targetState: string
}) {
  const [stateName, setStateName] = useState('')
  const [messageKey, setMessageKey] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState('custom')
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  useEffect(() => {
    if (open) {
      setStateName('')
      setMessageKey('')
      setDescription('')
      setCategory('custom')
      setError(null)
    }
  }, [open])

  if (!open) return null

  const validate = (): string | null => {
    const trimmed = stateName.trim().toUpperCase()
    if (!trimmed) return 'Inserisci un nome.'
    if (!/^[A-Z][A-Z0-9_]*$/.test(trimmed)) return 'Solo lettere maiuscole, cifre e underscore.'
    if (trimmed.length > 30) return 'Massimo 30 caratteri.'
    if (existingStates.includes(trimmed)) return 'Questo stato esiste gia\'.'
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
        sourceState,
        targetState,
      })
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
            <Plus className="h-4 w-4 text-teal-600" /> Inserisci stato tra
          </h2>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground p-1">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="flex items-center gap-2 text-xs bg-muted/50 rounded p-2">
          <code className="font-mono font-bold">{sourceState}</code>
          <ArrowDown className="h-3 w-3 text-muted-foreground" />
          <span className="text-teal-600 font-bold">NUOVO</span>
          <ArrowDown className="h-3 w-3 text-muted-foreground" />
          <code className="font-mono font-bold">{targetState}</code>
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Nome stato</label>
          <input
            value={stateName}
            onChange={e => setStateName(e.target.value.toUpperCase())}
            placeholder="ES. STEP_INTERMEDIO"
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-sm outline-none focus:border-emerald-500 font-mono"
          />
        </div>

        <div>
          <label className="text-xs font-medium text-muted-foreground">Messaggio</label>
          <select
            value={messageKey}
            onChange={e => setMessageKey(e.target.value)}
            className="mt-1 w-full rounded border bg-background px-2 py-1.5 text-xs outline-none focus:border-emerald-500 font-mono"
          >
            <option value="">-- scegli un messaggio --</option>
            {(meta?.messages ?? []).map(m => (
              <option key={m.key} value={m.key}>[{m.category}] {m.key}</option>
            ))}
          </select>
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
            placeholder="Opzionale..."
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
          <Button variant="outline" onClick={onClose} disabled={creating}>Annulla</Button>
          <Button onClick={submit} disabled={creating} className="bg-teal-600 hover:bg-teal-700">
            {creating ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <Plus className="h-3 w-3 mr-1" />}
            Inserisci
          </Button>
        </div>
      </div>
    </div>
  )
}

/* =================================================================
 *  Custom edge with insert "+" button
 * ================================================================= */

function InsertableEdge({
  id, sourceX, sourceY, targetX, targetY, style, markerEnd, data,
}: {
  id: string
  sourceX: number
  sourceY: number
  targetX: number
  targetY: number
  style?: React.CSSProperties
  markerEnd?: string
  data?: { kind: string; onInsert?: (edgeId: string) => void }
}) {
  const midX = (sourceX + targetX) / 2
  const midY = (sourceY + targetY) / 2

  // Simple straight or slightly curved path
  const dx = targetX - sourceX
  const dy = targetY - sourceY
  const controlOffset = Math.abs(dx) > 20 ? Math.min(Math.abs(dx) * 0.3, 60) : 0

  const path = controlOffset > 0
    ? `M ${sourceX} ${sourceY} C ${sourceX} ${sourceY + dy * 0.3}, ${targetX} ${targetY - dy * 0.3}, ${targetX} ${targetY}`
    : `M ${sourceX} ${sourceY} L ${targetX} ${targetY}`

  return (
    <>
      <path
        id={id}
        d={path}
        style={style}
        fill="none"
        markerEnd={markerEnd}
      />
      {data?.kind !== 'code' && data?.onInsert && (
        <foreignObject
          x={midX - 10}
          y={midY - 10}
          width={20}
          height={20}
          className="overflow-visible"
        >
          <button
            onClick={(e) => {
              e.stopPropagation()
              data.onInsert?.(id)
            }}
            className="w-5 h-5 rounded-full bg-white border-2 border-teal-400 text-teal-600 flex items-center justify-center hover:bg-teal-50 hover:border-teal-500 transition-colors shadow-sm"
            title="Inserisci stato qui"
          >
            <Plus className="h-3 w-3" />
          </button>
        </foreignObject>
      )}
    </>
  )
}

/* =================================================================
 *  Main editor (inside ReactFlowProvider)
 * ================================================================= */

function FlowEditor() {
  const [graph, setGraph] = useState<GraphResponse | null>(null)
  const [meta, setMeta] = useState<MetaResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [nodes, setNodes] = useState<Node[]>([])
  const [edges, setEdges] = useState<Edge[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [showCodeEdges, setShowCodeEdges] = useState(false)
  const [showAddDialog, setShowAddDialog] = useState(false)
  const [showNewMessageDialog, setShowNewMessageDialog] = useState(false)
  const [showInsertDialog, setShowInsertDialog] = useState<{ source: string; target: string } | null>(null)
  const [search, setSearch] = useState('')
  const [saving, setSaving] = useState(false)
  const [savedFlash, setSavedFlash] = useState(false)
  const [dirtyNodes, setDirtyNodes] = useState<Set<string>>(new Set())
  const [editedData, setEditedData] = useState<Map<string, FlowStateNode>>(new Map())
  const [editedMessages, setEditedMessages] = useState<Map<string, string>>(new Map())
  const dirtyPositions = useRef<Map<string, { x: number; y: number }>>(new Map())
  const { fitView } = useReactFlow()

  /* -- Shared keys: per ogni message_key, lista degli stati che la usano -- */
  const sharedKeys = useMemo<Map<string, string[]>>(() => {
    const map = new Map<string, string[]>()
    for (const n of graph?.nodes ?? []) {
      const keys = [n.message_key, n.fallback_key].filter((k): k is string => !!k)
      for (const k of keys) {
        const list = map.get(k) ?? []
        if (!list.includes(n.state)) list.push(n.state)
        map.set(k, list)
      }
    }
    return map
  }, [graph])

  const handleMessageEdit = useCallback((key: string, text: string) => {
    setEditedMessages(prev => {
      const next = new Map(prev)
      next.set(key, text)
      return next
    })
  }, [])

  const handleCreateMessage = useCallback(() => {
    setShowNewMessageDialog(true)
  }, [])

  /* -- Fetch graph + meta -- */

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

  /* -- Handle insert edge click -- */
  const edgesRef = useRef<Edge[]>([])
  edgesRef.current = edges

  const handleInsertEdge = useCallback((edgeId: string) => {
    const edge = edgesRef.current.find(e => e.id === edgeId)
    if (!edge) return
    setShowInsertDialog({ source: edge.source, target: edge.target })
  }, [])

  /* -- Build nodes/edges from graph -- */

  useEffect(() => {
    if (!graph) return

    // Determine incoming edges per node (to detect triggers)
    const incomingEdges = new Set<string>()
    for (const e of graph.buttonEdges) {
      if (e.source !== e.target) { // skip self-loops
        incomingEdges.add(e.target)
      }
    }
    for (const e of graph.codeEdges) {
      if (e.source !== e.target) {
        incomingEdges.add(e.target)
      }
    }

    // Determine which targets point "back up" (to an already-visited node in topo order)
    // We do a simple approach: collect all forward targets; anything pointing to a node
    // that appears before it in topo is a back-edge, rendered as a goto ref
    const stateOrder = new Map<string, number>()
    graph.nodes.forEach((n, i) => stateOrder.set(n.state, i))

    // Collect all edges, detect back-edges (goto refs)
    const forwardButtonEdges: ButtonEdge[] = []
    const gotoRefs: { source: string; targetState: string; edgeId: string; label: string }[] = []
    const gotoRefNodeIds = new Set<string>()

    for (const e of graph.buttonEdges) {
      // Skip self-loops (re-prompt loops)
      if (e.source === e.target) continue

      const sourceOrder = stateOrder.get(e.source) ?? 999
      const targetOrder = stateOrder.get(e.target) ?? 999

      // If target comes before source in the natural order, it's a back-edge
      if (targetOrder < sourceOrder) {
        const refId = `goto_${e.source}_${e.target}_${e.id}`
        gotoRefNodeIds.add(refId)
        gotoRefs.push({ source: e.source, targetState: e.target, edgeId: refId, label: e.label })
      } else {
        forwardButtonEdges.push(e)
      }
    }

    const rfNodes: Node[] = []

    for (const n of graph.nodes) {
      const isTrigger = !incomingEdges.has(n.state) || n.state === 'NEW'
      rfNodes.push({
        id: n.id,
        type: 'flowCard',
        position: { x: 0, y: 0 },
        draggable: false,
        selectable: true,
        data: { ...n, isTrigger, isGotoRef: false } as unknown as Record<string, unknown>,
      })
    }

    // Add goto reference nodes
    for (const ref of gotoRefs) {
      rfNodes.push({
        id: ref.edgeId,
        type: 'flowCard',
        position: { x: 0, y: 0 },
        draggable: false,
        selectable: false,
        data: {
          state: ref.edgeId,
          isGotoRef: true,
          gotoTarget: ref.targetState,
          isTrigger: false,
          // Fill in dummy fields so TS is happy
          id: ref.edgeId,
          type: 'simple',
          is_custom: false,
          category: 'custom',
          description: null,
          message_key: '',
          message_text: null,
          fallback_key: null,
          fallback_text: null,
          buttons: [],
          input_rules: [],
          transitions: [],
          on_enter_actions: [],
          position: null,
          sort_order: 0,
        } as unknown as Record<string, unknown>,
      })
    }

    // Build edges for layout and display
    const rfEdges: Edge[] = []

    for (const e of forwardButtonEdges) {
      const color = e.kind === 'rule'
        ? '#0891b2'
        : e.kind === 'transition'
          ? '#a855f7'
          : (e.side_effect ? '#f59e0b' : '#10b981')

      rfEdges.push({
        id: e.id,
        source: e.source,
        target: e.target,
        type: 'insertable',
        style: { stroke: color, strokeWidth: 2 },
        markerEnd: { type: MarkerType.ArrowClosed, color },
        data: { kind: e.kind, side_effect: e.side_effect, onInsert: handleInsertEdge },
      })
    }

    // Edges from source to goto ref nodes
    for (const ref of gotoRefs) {
      rfEdges.push({
        id: `edge_to_${ref.edgeId}`,
        source: ref.source,
        target: ref.edgeId,
        type: 'insertable',
        style: { stroke: '#94a3b8', strokeWidth: 1.5, strokeDasharray: '6 3' },
        markerEnd: { type: MarkerType.ArrowClosed, color: '#94a3b8' },
        data: { kind: 'goto', onInsert: undefined },
      })
    }

    if (showCodeEdges) {
      for (const e of graph.codeEdges) {
        if (e.source === e.target) continue
        rfEdges.push({
          id: e.id,
          source: e.source,
          target: e.target,
          type: 'insertable',
          style: { stroke: '#94a3b8', strokeWidth: 1, strokeDasharray: '4 4' },
          markerEnd: { type: MarkerType.ArrowClosed, color: '#94a3b8' },
          data: { kind: 'code', onInsert: undefined },
        })
      }
    }

    // Auto-layout with dagre
    const layoutedNodes = autoLayout(rfNodes, rfEdges)

    setNodes(layoutedNodes)
    setEdges(rfEdges)

    // Save positions for all nodes
    layoutedNodes.forEach(n => {
      dirtyPositions.current.set(n.id, n.position)
    })

    setTimeout(() => fitView({ padding: 0.15, duration: 400 }), 100)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [graph, showCodeEdges])

  /* -- Selection -- */

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

    // Update live on canvas
    setNodes(nds => nds.map(n => {
      if (n.id !== updated.id) return n
      const prevData = n.data as unknown as FlowNodeData
      return { ...n, data: { ...updated, isTrigger: prevData.isTrigger, isGotoRef: false } as unknown as Record<string, unknown> }
    }))
  }, [])

  /* -- Save all -- */

  const saveAll = useCallback(async () => {
    setSaving(true)
    try {
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
              on_enter_actions: data.on_enter_actions.length > 0 ? data.on_enter_actions : null,
            }),
          }),
        )
      }

      // Save positions in bulk (only real states, not goto refs)
      const realPositions = Array.from(dirtyPositions.current.entries())
        .filter(([id]) => !id.startsWith('goto_'))
        .filter(([id]) => graph?.nodes.some(n => n.id === id))
      if (realPositions.length > 0) {
        updates.push(
          apiFetch('/admin/bot-flow-states/positions', {
            method: 'PUT',
            body: JSON.stringify({
              positions: realPositions.map(([state, position]) => ({ state, position })),
            }),
          }),
        )
      }

      // Save dirty messages
      for (const [key, text] of editedMessages.entries()) {
        updates.push(
          apiFetch(`/admin/bot-messages/${encodeURIComponent(key)}`, {
            method: 'PUT',
            body: JSON.stringify({ text }),
          }),
        )
      }

      await Promise.all(updates)

      dirtyPositions.current.clear()
      setDirtyNodes(new Set())
      setEditedData(new Map())
      setEditedMessages(new Map())
      setSavedFlash(true)
      setTimeout(() => setSavedFlash(false), 2500)

      await fetchAll()
    } catch (e) {
      console.error('Save failed', e)
      alert('Errore durante il salvataggio. Controlla la console.')
    } finally {
      setSaving(false)
    }
  }, [dirtyNodes, editedData, editedMessages, fetchAll, graph])

  /* -- Create new state -- */

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

  /* -- Insert state between two nodes -- */

  const handleInsertState = useCallback(async (data: {
    state: string; message_key: string; description?: string; category?: string
    sourceState: string; targetState: string
  }) => {
    // 1. Create the new state with a button pointing to targetState
    await apiFetch<FlowStateNode>('/admin/bot-flow-states', {
      method: 'POST',
      body: JSON.stringify({
        state: data.state,
        message_key: data.message_key,
        description: data.description,
        category: data.category,
        position: { x: 200, y: 200 },
        buttons: [{ label: 'Continua', target_state: data.targetState }],
      }),
    })

    // 2. Rewire the source: update the button/rule/transition that pointed to targetState
    const sourceNode = editedData.get(data.sourceState) ?? graph?.nodes.find(n => n.state === data.sourceState)
    if (sourceNode) {
      const updatedButtons = sourceNode.buttons.map(b =>
        b.target_state === data.targetState ? { ...b, target_state: data.state } : b
      )
      const updatedRules = sourceNode.input_rules.map(r =>
        r.next_state === data.targetState ? { ...r, next_state: data.state } : r
      )
      const updatedTransitions = sourceNode.transitions.map(t =>
        t.then === data.targetState ? { ...t, then: data.state } : t
      )

      await apiFetch(`/admin/bot-flow-states/${data.sourceState}`, {
        method: 'PUT',
        body: JSON.stringify({
          message_key: sourceNode.message_key,
          fallback_key: sourceNode.fallback_key,
          description: sourceNode.description,
          buttons: updatedButtons,
          input_rules: updatedRules.length > 0 ? updatedRules : null,
          transitions: updatedTransitions.length > 0 ? updatedTransitions : null,
          on_enter_actions: sourceNode.on_enter_actions.length > 0 ? sourceNode.on_enter_actions : null,
        }),
      })
    }

    await fetchAll()
    setSelectedId(data.state)
  }, [fetchAll, editedData, graph])

  /* -- Delete selected -- */

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

  /* -- Search filter -- */

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
      style: { ...n.style, opacity: matchingIds.has(n.id) ? 1 : 0.15 },
    }))
  }, [nodes, search, graph])

  /* -- Edge types (with insert button) -- */
  const edgeTypes = useMemo(() => ({
    insertable: InsertableEdge,
  }), [])

  /* -- Render -- */

  if (loading) {
    return (
      <div className="flex justify-center py-24">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const dirtyCount = dirtyNodes.size + editedMessages.size
  const hasChanges = dirtyCount > 0

  return (
    <div className="relative w-full" style={{ height: 'calc(100vh - 130px)' }}>
      <ReactFlow
        nodes={filteredNodes}
        edges={edges}
        onNodeClick={(_, n) => {
          const nd = n.data as unknown as FlowNodeData
          // Don't select goto ref nodes, but navigate to their target instead
          if (nd.isGotoRef && nd.gotoTarget) {
            const target = graph?.nodes.find(gn => gn.state === nd.gotoTarget)
            if (target) setSelectedId(target.id)
            return
          }
          setSelectedId(n.id)
        }}
        onPaneClick={() => setSelectedId(null)}
        nodeTypes={nodeTypes}
        edgeTypes={edgeTypes}
        nodesDraggable={false}
        nodesConnectable={false}
        fitView
        minZoom={0.15}
        maxZoom={1.5}
        proOptions={{ hideAttribution: true }}
      >
        <Background gap={24} size={1} color="#e5e7eb" />
        <Controls position="bottom-left" showInteractive={false} />

        {/* -- Toolbar top -- */}
        <div className="absolute top-3 left-3 z-10">
          <div className="bg-background border rounded-lg shadow-sm p-2.5 flex items-center gap-2.5">
            <h1 className="text-sm font-bold pl-1 pr-1">Flusso Bot</h1>
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
        </div>

        {/* -- Toolbar top right: search + toggle -- */}
        <div className="absolute top-3 right-3 z-10">
          <div className="bg-background border rounded-lg shadow-sm p-2.5 flex items-center gap-2.5">
            <div className="relative">
              <Search className="absolute left-2 top-1/2 h-3 w-3 -translate-y-1/2 text-muted-foreground" />
              <input
                type="text"
                placeholder="Cerca stati..."
                value={search}
                onChange={e => setSearch(e.target.value)}
                className="pl-7 pr-2 py-1 text-xs rounded border bg-background outline-none focus:border-emerald-500 w-44"
              />
            </div>
            <label className="flex items-center gap-1.5 text-xs cursor-pointer select-none whitespace-nowrap">
              <input
                type="checkbox"
                checked={showCodeEdges}
                onChange={e => setShowCodeEdges(e.target.checked)}
                className="cursor-pointer"
              />
              <span>Mostra codice</span>
            </label>
          </div>
        </div>

        {/* -- Legend bottom -- */}
        <div className="absolute bottom-3 left-1/2 -translate-x-1/2 z-10">
          <div className="bg-background border rounded-lg shadow-sm px-3 py-1.5 flex items-center gap-3 text-[10px] flex-wrap">
            <div className="flex items-center gap-1">
              <Circle className="h-2.5 w-2.5 text-green-500 fill-green-500" />
              <span>Trigger</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-0.5 bg-emerald-500 rounded" />
              <span>Bottone</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-0.5 bg-amber-500 rounded" />
              <span>Side-effect</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-0.5 bg-cyan-600 rounded" />
              <span>Validazione</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-0.5 bg-purple-500 rounded" />
              <span>Fork</span>
            </div>
            <div className="flex items-center gap-1">
              <div className="w-4 h-px border-t border-dashed border-gray-400" style={{ width: 16 }} />
              <span>Codice / Goto</span>
            </div>
          </div>
        </div>
      </ReactFlow>

      {/* -- Side panel -- */}
      {selectedNode && (
        <NodeEditPanel
          node={selectedNode}
          allNodes={graph?.nodes ?? []}
          meta={meta}
          messageOverrides={editedMessages}
          sharedKeys={sharedKeys}
          onClose={() => setSelectedId(null)}
          onChange={handleNodeChange}
          onMessageEdit={handleMessageEdit}
          onCreateMessage={handleCreateMessage}
          onDelete={handleDelete}
          saving={saving}
        />
      )}

      {/* -- Add state dialog -- */}
      <AddStateDialog
        open={showAddDialog}
        onClose={() => setShowAddDialog(false)}
        onCreate={handleCreate}
        existingStates={graph?.nodes.map(n => n.state) ?? []}
        meta={meta}
      />

      {/* -- Add message dialog -- */}
      <AddMessageDialog
        open={showNewMessageDialog}
        onClose={() => setShowNewMessageDialog(false)}
        existingKeys={(meta?.messages ?? []).map(m => m.key)}
        categories={meta?.categories ?? []}
        suggestedKey={selectedNode ? `msg_${selectedNode.state.toLowerCase()}` : ''}
        onCreate={async (data) => {
          await apiFetch('/admin/bot-messages', {
            method: 'POST',
            body: JSON.stringify(data),
          })
          await fetchAll()
        }}
      />

      {/* -- Insert state dialog -- */}
      <InsertStateDialog
        open={showInsertDialog !== null}
        onClose={() => setShowInsertDialog(null)}
        onCreate={handleInsertState}
        existingStates={graph?.nodes.map(n => n.state) ?? []}
        meta={meta}
        sourceState={showInsertDialog?.source ?? ''}
        targetState={showInsertDialog?.target ?? ''}
      />
    </div>
  )
}

/* =================================================================
 *  Outer wrapper with provider
 * ================================================================= */

export function Flusso() {
  return (
    <ReactFlowProvider>
      <FlowEditor />
    </ReactFlowProvider>
  )
}
