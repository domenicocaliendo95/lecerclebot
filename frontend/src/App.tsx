import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { AuthProvider } from '@/hooks/use-auth'
import { RequireAuth } from '@/components/auth/require-auth'
import { AppLayout } from '@/components/layout/app-layout'
import { Login } from '@/pages/login'
import { Dashboard } from '@/pages/dashboard'
import { Calendario } from '@/pages/calendario'
import { Prenotazioni } from '@/pages/prenotazioni'
import { Giocatori } from '@/pages/giocatori'
import { Sessioni } from '@/pages/sessioni'
import { Match } from '@/pages/match'
import { Impostazioni } from '@/pages/impostazioni'
import { Messaggi } from '@/pages/messaggi'
import { Flusso } from '@/pages/flusso'
import { Moduli } from '@/pages/moduli'
import { Feedback } from '@/pages/feedback'

export default function App() {
  return (
    <BrowserRouter basename="/panel">
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route
            element={
              <RequireAuth>
                <AppLayout />
              </RequireAuth>
            }
          >
            <Route index element={<Dashboard />} />
            <Route path="calendario" element={<Calendario />} />
            <Route path="prenotazioni" element={<Prenotazioni />} />
            <Route path="giocatori" element={<Giocatori />} />
            <Route path="sessioni" element={<Sessioni />} />
            <Route path="match" element={<Match />} />
            <Route path="messaggi" element={<Messaggi />} />
            <Route path="flusso" element={<Flusso />} />
            <Route path="moduli" element={<Moduli />} />
            <Route path="feedback" element={<Feedback />} />
            <Route path="impostazioni" element={<Impostazioni />} />
          </Route>
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
