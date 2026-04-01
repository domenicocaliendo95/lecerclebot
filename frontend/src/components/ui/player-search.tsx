import { useState, useEffect, useRef } from 'react'
import { Search, Loader2 } from 'lucide-react'
import { apiFetch } from '@/hooks/use-api'
import { inputClass } from '@/components/ui/form-dialog'
import type { User } from '@/types/api'

interface PlayerSearchProps {
  value: string
  onChange: (userId: string, userName: string) => void
  placeholder?: string
}

export function PlayerSearch({ value, onChange, placeholder = 'Cerca giocatore...' }: PlayerSearchProps) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<User[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const [selectedName, setSelectedName] = useState('')
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (query.length < 2) { setResults([]); return }
    setLoading(true)
    const timeout = setTimeout(() => {
      apiFetch<{ data: User[] }>(`/admin/users/search?q=${encodeURIComponent(query)}`)
        .then(res => { setResults(res.data); setOpen(true) })
        .catch(() => setResults([]))
        .finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(timeout)
  }, [query])

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const select = (user: User) => {
    onChange(String(user.id), user.name)
    setSelectedName(user.name)
    setQuery('')
    setOpen(false)
  }

  return (
    <div ref={ref} className="relative">
      <div className="relative">
        <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <input
          type="text"
          value={query || (value ? selectedName || `ID: ${value}` : '')}
          onChange={e => { setQuery(e.target.value); if (!e.target.value) onChange('', '') }}
          onFocus={() => query.length >= 2 && setOpen(true)}
          placeholder={placeholder}
          className={`${inputClass} pl-9`}
        />
        {loading && <Loader2 className="absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 animate-spin text-muted-foreground" />}
      </div>

      {open && results.length > 0 && (
        <div className="absolute z-50 mt-1 w-full rounded-lg border bg-card shadow-lg max-h-48 overflow-y-auto">
          {results.map(u => (
            <button
              key={u.id}
              type="button"
              className="flex w-full items-center justify-between px-3 py-2 text-sm hover:bg-muted transition-colors"
              onClick={() => select(u)}
            >
              <div>
                <span className="font-medium">{u.name}</span>
                <span className="text-muted-foreground ml-2 text-xs">{u.phone}</span>
              </div>
              <span className="text-xs text-muted-foreground">ELO {u.elo_rating}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
