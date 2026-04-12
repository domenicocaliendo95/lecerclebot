import { Menu, LogOut, Bell } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetTrigger, SheetTitle } from '@/components/ui/sheet'
import { NavLink, useLocation } from 'react-router-dom'
import {
  LayoutDashboard,
  Calendar,
  Users,
  MessageSquare,
  MessageSquareText,
  GitBranch,
  Settings,
  Trophy,
} from 'lucide-react'
import { useAuth } from '@/hooks/use-auth'

const navigation = [
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

const pageTitles: Record<string, string> = {
  '/': 'Dashboard',
  '/calendario': 'Calendario',
  '/prenotazioni': 'Prenotazioni',
  '/giocatori': 'Giocatori',
  '/sessioni': 'Sessioni Bot',
  '/match': 'Match & Classifica',
  '/messaggi': 'Messaggi Bot',
  '/flusso': 'Flusso Conversazionale',
  '/impostazioni': 'Impostazioni',
}

export function Header() {
  const { user, logout } = useAuth()
  const location = useLocation()

  const pageTitle = pageTitles[location.pathname] ?? 'Le Cercle'

  const initials = (user?.name ?? 'A')
    .split(' ')
    .map(w => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)

  return (
    <>
      {/* Mobile header */}
      <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 lg:hidden">
        <div className="flex items-center gap-3">
          <Sheet>
            <SheetTrigger>
              <Button variant="ghost" size="icon" className="h-9 w-9">
                <Menu className="h-5 w-5" />
              </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-64 p-0 bg-slate-900 border-slate-700">
              <SheetTitle className="sr-only">Menu navigazione</SheetTitle>
              <div className="flex h-16 items-center gap-3 border-b border-slate-700/50 px-6">
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold text-sm">
                  LC
                </div>
                <div>
                  <p className="text-sm font-semibold leading-none text-white">Le Cercle</p>
                  <p className="text-[11px] text-slate-400">Tennis Club</p>
                </div>
              </div>
              <nav className="space-y-1 px-2 py-4">
                {navigation.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.to === '/'}
                    className={({ isActive }) =>
                      `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-150 ${
                        isActive
                          ? 'bg-emerald-600/15 text-emerald-400 border-l-[3px] border-emerald-400 pl-[9px]'
                          : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200 border-l-[3px] border-transparent pl-[9px]'
                      }`
                    }
                  >
                    <item.icon className="h-[18px] w-[18px]" />
                    {item.label}
                  </NavLink>
                ))}
              </nav>
            </SheetContent>
          </Sheet>
          <span className="text-sm font-semibold text-slate-800">{pageTitle}</span>
        </div>
        <div className="flex items-center gap-2">
          <button className="relative rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" title="Notifiche">
            <Bell className="h-4 w-4" />
          </button>
          <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
            {initials}
          </div>
          <button
            onClick={logout}
            className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
            title="Logout"
          >
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </header>

      {/* Desktop header bar */}
      <header className="sticky top-0 z-20 hidden h-14 items-center justify-between border-b border-slate-200 bg-white/80 backdrop-blur-sm px-6 lg:flex">
        <h1 className="text-base font-semibold text-slate-800">{pageTitle}</h1>
        <div className="flex items-center gap-3">
          <button className="relative rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" title="Notifiche">
            <Bell className="h-[18px] w-[18px]" />
          </button>
          <div className="h-6 w-px bg-slate-200" />
          <div className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-xs font-semibold text-emerald-700">
              {initials}
            </div>
            <span className="text-sm font-medium text-slate-700">{user?.name ?? 'Admin'}</span>
          </div>
        </div>
      </header>
    </>
  )
}
