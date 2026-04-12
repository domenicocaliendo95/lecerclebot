import { Menu, LogOut } from 'lucide-react'
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

const pageDescriptions: Record<string, string> = {
  '/': 'Panoramica del circolo',
  '/calendario': 'Vista giornaliera e settimanale',
  '/prenotazioni': 'Gestione prenotazioni campi',
  '/giocatori': 'Anagrafica giocatori',
  '/sessioni': 'Sessioni conversazionali del bot',
  '/match': 'Risultati e classifica ELO',
  '/messaggi': 'Template messaggi del bot',
  '/flusso': 'Configurazione flusso conversazionale',
  '/impostazioni': 'Prezzi, promemoria e configurazione',
}

export function Header() {
  const { user, logout } = useAuth()
  const location = useLocation()

  const pageTitle = pageTitles[location.pathname] ?? 'Le Cercle'
  const pageDesc = pageDescriptions[location.pathname]

  const initials = (user?.name ?? 'A')
    .split(' ')
    .map(w => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)

  return (
    <>
      {/* Mobile header */}
      <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-4 border-b border-slate-200/80 bg-white/90 backdrop-blur-md px-4 lg:hidden">
        <div className="flex items-center gap-3">
          <Sheet>
            <SheetTrigger>
              <Button variant="ghost" size="icon" className="h-9 w-9">
                <Menu className="h-5 w-5" />
              </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-64 p-0 bg-slate-900 border-slate-700">
              <SheetTitle className="sr-only">Menu navigazione</SheetTitle>
              <div className="flex h-16 items-center gap-3 border-b border-white/[0.06] px-6">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white font-bold text-sm shadow-lg shadow-emerald-900/30">
                  LC
                </div>
                <div>
                  <p className="text-[13px] font-semibold leading-none tracking-tight text-white">Le Cercle</p>
                  <p className="text-[11px] text-slate-500 mt-0.5">Tennis Club</p>
                </div>
              </div>
              <nav className="space-y-0.5 px-2 py-4">
                {navigation.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.to === '/'}
                    className={({ isActive }) =>
                      `flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] transition-all duration-200 ${
                        isActive
                          ? 'bg-gradient-to-r from-emerald-600/20 to-emerald-600/5 text-emerald-400 font-medium'
                          : 'text-slate-400 hover:bg-white/[0.04] hover:text-slate-200 font-normal'
                      }`
                    }
                  >
                    <item.icon className="h-[17px] w-[17px]" />
                    {item.label}
                  </NavLink>
                ))}
              </nav>
            </SheetContent>
          </Sheet>
          <span className="text-sm font-semibold text-slate-800">{pageTitle}</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200/50">
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
      <header className="sticky top-0 z-20 hidden h-14 items-center justify-between border-b border-slate-200/60 bg-white/80 backdrop-blur-md px-6 lg:flex">
        <div>
          <h1 className="text-sm font-semibold text-slate-800">{pageTitle}</h1>
          {pageDesc && (
            <p className="text-[11px] text-slate-400 -mt-0.5">{pageDesc}</p>
          )}
        </div>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200/50">
              {initials}
            </div>
            <span className="text-[13px] font-medium text-slate-600">{user?.name ?? 'Admin'}</span>
          </div>
        </div>
      </header>
    </>
  )
}
