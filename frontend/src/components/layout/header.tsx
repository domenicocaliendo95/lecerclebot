import { Menu, LogOut } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetTrigger, SheetTitle } from '@/components/ui/sheet'
import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  Calendar,
  Users,
  MessageSquare,
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
  { label: 'Impostazioni', to: '/impostazioni', icon: Settings },
]

export function Header() {
  const { user, logout } = useAuth()

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b bg-card px-6 lg:hidden">
      <div className="flex items-center gap-4">
        <Sheet>
          <SheetTrigger>
            <Button variant="ghost" size="icon">
              <Menu className="h-5 w-5" />
            </Button>
          </SheetTrigger>
          <SheetContent side="left" className="w-64 p-0">
            <SheetTitle className="sr-only">Menu navigazione</SheetTitle>
            <div className="flex h-16 items-center gap-3 border-b px-6">
              <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold text-sm">
                LC
              </div>
              <div>
                <p className="text-sm font-semibold leading-none">Le Cercle</p>
                <p className="text-xs text-muted-foreground">Tennis Club</p>
              </div>
            </div>
            <nav className="space-y-1 px-3 py-4">
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
          </SheetContent>
        </Sheet>
        <p className="text-sm font-semibold">Le Cercle Tennis Club</p>
      </div>
      <div className="flex items-center gap-2">
        <span className="text-sm text-muted-foreground hidden sm:block">{user?.name}</span>
        <button
          onClick={logout}
          className="rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
          title="Logout"
        >
          <LogOut className="h-4 w-4" />
        </button>
      </div>
    </header>
  )
}
