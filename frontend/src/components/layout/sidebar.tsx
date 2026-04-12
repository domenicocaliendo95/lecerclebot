import { useState } from 'react'
import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  Calendar,
  Users,
  MessageSquare,
  MessageSquareText,
  GitBranch,
  Settings,
  Trophy,
  LogOut,
  ChevronsLeft,
  ChevronsRight,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '@/hooks/use-auth'

interface NavItem {
  label: string
  to: string
  icon: LucideIcon
}

const navigation: NavItem[] = [
  { label: 'Dashboard', to: '/', icon: LayoutDashboard },
  { label: 'Calendario', to: '/calendario', icon: Calendar },
  { label: 'Prenotazioni', to: '/prenotazioni', icon: Calendar },
  { label: 'Giocatori', to: '/giocatori', icon: Users },
  { label: 'Sessioni Bot', to: '/sessioni', icon: MessageSquare },
  { label: 'Match', to: '/match', icon: Trophy },
  { label: 'Messaggi Bot', to: '/messaggi', icon: MessageSquareText },
  { label: 'Flusso', to: '/flusso', icon: GitBranch },
  { label: 'Impostazioni', to: '/impostazioni', icon: Settings },
]

export function Sidebar() {
  const { user, logout } = useAuth()
  const [collapsed, setCollapsed] = useState(false)

  const initials = (user?.name ?? 'A')
    .split(' ')
    .map(w => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)

  return (
    <aside
      className={`fixed inset-y-0 left-0 z-30 hidden flex-col bg-slate-900 text-slate-300 transition-all duration-300 lg:flex ${
        collapsed ? 'w-[68px]' : 'w-64'
      }`}
    >
      {/* Logo / Brand */}
      <div className="flex h-16 items-center gap-3 border-b border-slate-700/50 px-4">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold text-sm">
          LC
        </div>
        {!collapsed && (
          <div className="overflow-hidden">
            <p className="text-sm font-semibold leading-none text-white">Le Cercle</p>
            <p className="text-[11px] text-slate-400">Tennis Club</p>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 px-2 py-4 overflow-y-auto">
        {navigation.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            title={collapsed ? item.label : undefined}
            className={({ isActive }) =>
              `group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-150 ${
                isActive
                  ? 'bg-emerald-600/15 text-emerald-400 border-l-[3px] border-emerald-400 ml-0 pl-[9px]'
                  : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200 border-l-[3px] border-transparent ml-0 pl-[9px]'
              } ${collapsed ? 'justify-center px-0 pl-0 border-l-0 ml-0' : ''}`
            }
          >
            <item.icon className={`h-[18px] w-[18px] shrink-0 transition-colors ${collapsed ? '' : ''}`} />
            {!collapsed && <span>{item.label}</span>}
          </NavLink>
        ))}
      </nav>

      {/* Collapse toggle */}
      <div className="px-2 pb-1">
        <button
          onClick={() => setCollapsed(c => !c)}
          className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs text-slate-500 hover:bg-slate-800 hover:text-slate-300 transition-colors"
          title={collapsed ? 'Espandi' : 'Comprimi'}
        >
          {collapsed ? <ChevronsRight className="h-4 w-4" /> : <><ChevronsLeft className="h-4 w-4" /><span>Comprimi</span></>}
        </button>
      </div>

      {/* User + Logout */}
      <div className="border-t border-slate-700/50 p-2">
        <div className={`flex items-center rounded-lg px-3 py-2.5 ${collapsed ? 'justify-center' : 'justify-between'}`}>
          {!collapsed ? (
            <div className="flex items-center gap-3 min-w-0">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-slate-200">
                {initials}
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-slate-200 truncate">{user?.name ?? 'Admin'}</p>
                <p className="text-[11px] text-slate-500 truncate">{user?.phone}</p>
              </div>
            </div>
          ) : (
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-slate-200" title={user?.name ?? 'Admin'}>
              {initials}
            </div>
          )}
          {!collapsed && (
            <button
              onClick={logout}
              className="shrink-0 rounded-md p-1.5 text-slate-500 hover:bg-slate-800 hover:text-slate-300 transition-colors"
              title="Logout"
            >
              <LogOut className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>
    </aside>
  )
}

export function useSidebarWidth() {
  // This is a simple approach; the sidebar manages its own state internally
  // The layout reads from a CSS variable or fixed value
  return 256 // default expanded width
}
