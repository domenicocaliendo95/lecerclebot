import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './sidebar'
import { Header } from './header'

export function AppLayout() {
  const location = useLocation()

  return (
    <div className="min-h-screen main-content-bg">
      <Sidebar />
      <div className="lg:pl-64 transition-all duration-300">
        <Header />
        <main>
          <div key={location.pathname} className="mx-auto max-w-7xl p-6 page-enter">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
