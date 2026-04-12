import { Outlet } from 'react-router-dom'
import { Sidebar } from './sidebar'
import { Header } from './header'

export function AppLayout() {
  return (
    <div className="min-h-screen bg-slate-50">
      <Sidebar />
      <div className="lg:pl-64 transition-all duration-300">
        <Header />
        <main>
          <div className="mx-auto max-w-7xl p-6">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
