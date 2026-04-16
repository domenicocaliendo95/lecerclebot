import { useState } from 'react'
import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  Calendar,
  Users,
  MessageSquare,
  MessageSquareText,
  GitBranch,
  Package,
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
  group?: string
}

const navigation: NavItem[] = [
  { label: 'Dashboard', to: '/', icon: LayoutDashboard, group: 'principale' },
  { label: 'Calendario', to: '/calendario', icon: Calendar, group: 'principale' },
  { label: 'Prenotazioni', to: '/prenotazioni', icon: Calendar, group: 'principale' },
  { label: 'Giocatori', to: '/giocatori', icon: Users, group: 'principale' },
  { label: 'Match', to: '/match', icon: Trophy, group: 'principale' },
  { label: 'Sessioni Bot', to: '/sessioni', icon: MessageSquare, group: 'bot' },
  { label: 'Messaggi Bot', to: '/messaggi', icon: MessageSquareText, group: 'bot' },
  { label: 'Flusso', to: '/flusso', icon: GitBranch, group: 'bot' },
  { label: 'Moduli', to: '/moduli', icon: Package, group: 'bot' },
  { label: 'Impostazioni', to: '/impostazioni', icon: Settings, group: 'sistema' },
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

  let lastGroup = ''

  return (
    <aside
      className={`fixed inset-y-0 left-0 z-30 hidden flex-col bg-gradient-to-b from-slate-900 via-slate-900 to-slate-950 text-slate-300 transition-all duration-300 lg:flex ${
        collapsed ? 'w-[68px]' : 'w-64'
      }`}
    >
      {/* Logo / Brand */}
      <div className="flex h-16 items-center gap-3 border-b border-white/[0.06] px-4">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white font-bold text-sm shadow-lg shadow-emerald-900/30">
          LC
        </div>
        {!collapsed && (
          <div className="overflow-hidden">
            <p className="text-[13px] font-semibold leading-none tracking-tight text-white">Le Cercle</p>
            <p className="text-[11px] text-slate-500 mt-0.5">Tennis Club</p>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-0.5 px-2 py-4 overflow-y-auto">
        {navigation.map((item) => {
          const showDivider = !collapsed && item.group !== lastGroup && lastGroup !== ''
          lastGroup = item.group ?? ''

          return (
            <div key={item.to}>
              {showDivider && <div className="nav-divider" />}
              <NavLink
                to={item.to}
                end={item.to === '/'}
                title={collapsed ? item.label : undefined}
                className={({ isActive }) =>
                  `group flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] transition-all duration-200 ${
                    isActive
                      ? 'bg-gradient-to-r from-emerald-600/20 to-emerald-600/5 text-emerald-400 font-medium shadow-sm shadow-emerald-900/10'
                      : 'text-slate-400 hover:bg-white/[0.04] hover:text-slate-200 font-normal'
                  } ${collapsed ? 'justify-center px-0' : ''}`
                }
              >
                {({ isActive }) => (
                  <>
                    <item.icon
                      className={`shrink-0 transition-colors duration-200 ${
                        isActive ? 'h-[17px] w-[17px] text-emerald-400' : 'h-[17px] w-[17px] text-slate-500 group-hover:text-slate-300'
                      }`}
                    />
                    {!collapsed && <span className="transition-colors duration-200">{item.label}</span>}
                  </>
                )}
              </NavLink>
            </div>
          )
        })}
      </nav>

      {/* Collapse toggle */}
      <div className="px-2 pb-1">
        <button
          onClick={() => setCollapsed(c => !c)}
          className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs text-slate-500 hover:bg-white/[0.04] hover:text-slate-400 transition-colors"
          title={collapsed ? 'Espandi' : 'Comprimi'}
        >
          {collapsed ? <ChevronsRight className="h-4 w-4" /> : <><ChevronsLeft className="h-4 w-4" /><span>Comprimi</span></>}
        </button>
      </div>

      {/* User + Logout */}
      <div className="border-t border-white/[0.06] p-2">
        <div className={`flex items-center rounded-lg px-3 py-2.5 ${collapsed ? 'justify-center' : 'justify-between'}`}>
          {!collapsed ? (
            <div className="flex items-center gap-3 min-w-0">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-600 to-slate-700 text-xs font-semibold text-slate-200 ring-1 ring-white/10">
                {initials}
              </div>
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-slate-200 truncate">{user?.name ?? 'Admin'}</p>
                <p className="text-[11px] text-slate-500 truncate">{user?.phone}</p>
              </div>
            </div>
          ) : (
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-slate-600 to-slate-700 text-xs font-semibold text-slate-200 ring-1 ring-white/10" title={user?.name ?? 'Admin'}>
              {initials}
            </div>
          )}
          {!collapsed && (
            <button
              onClick={logout}
              className="shrink-0 rounded-md p-1.5 text-slate-500 hover:bg-white/[0.06] hover:text-slate-300 transition-colors"
              title="Logout"
            >
              <LogOut className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>

      {/* Version / copyright */}
      {!collapsed && (
        <div className="px-4 pb-3">
          <p className="text-[10px] text-slate-600 text-center">Le Cercle Bot v1.0</p>
        </div>
      )}
    </aside>
  )
}

export function useSidebarWidth() {
  return 256
}
