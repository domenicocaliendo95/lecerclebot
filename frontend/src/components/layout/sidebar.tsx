import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  Calendar,
  Users,
  MessageSquare,
  Settings,
  Trophy,
  LogOut,
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
  { label: 'Impostazioni', to: '/impostazioni', icon: Settings },
]

export function Sidebar() {
  const { user, logout } = useAuth()

  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r bg-card lg:flex">
      {/* Logo */}
      <div className="flex h-16 items-center gap-3 border-b px-6">
        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold text-sm">
          LC
        </div>
        <div>
          <p className="text-sm font-semibold leading-none">Le Cercle</p>
          <p className="text-xs text-muted-foreground">Tennis Club</p>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 px-3 py-4">
        {navigation.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                isActive
                  ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400'
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
              }`
            }
          >
            <item.icon className="h-4 w-4" />
            {item.label}
          </NavLink>
        ))}
      </nav>

      {/* User + Logout */}
      <div className="border-t p-3">
        <div className="flex items-center justify-between rounded-lg px-3 py-2">
          <div className="min-w-0">
            <p className="text-sm font-medium truncate">{user?.name ?? 'Admin'}</p>
            <p className="text-xs text-muted-foreground truncate">{user?.phone}</p>
          </div>
          <button
            onClick={logout}
            className="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
            title="Logout"
          >
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </div>
    </aside>
  )
}
