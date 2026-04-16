import { X } from 'lucide-react'

/**
 * Descrittore di un campo del config_schema esposto dal registry dei moduli.
 * Usato sia dall'editor del flusso (config del nodo) sia dal form preset
 * (config_defaults).
 */
export type ConfigField = {
  type: string
  label: string
  required?: boolean
  default?: unknown
  help?: string
  options?: { value: string; label: string }[]
  max?: number
}

export function FieldEditor({
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
