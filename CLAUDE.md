# Le Cercle Tennis Club — Bot WhatsApp
## Bibbia del Progetto — Aggiornata al 2026-04-06

---

## Identità

Bot WhatsApp per **Le Cercle Tennis Club**, circolo tennistico a **San Gennaro Vesuviano (NA)**. Il bot gestisce registrazione utenti, prenotazione campi, matchmaking tra giocatori e gestione profilo. Comunicazione in **italiano**, tono **amichevole, diretto, sportivo**. Massimo 3 righe per messaggio, max 1 emoji.

---

## Stack Tecnologico

| Componente | Tecnologia |
|---|---|
| Framework | Laravel 11 + Filament 3 (legacy admin) |
| Frontend | React SPA (Vite + Tailwind v4 + Shadcn/UI) in `frontend/`, build → `public/panel/` |
| Database | MySQL (`lecercle_db`) |
| AI testi | Google Gemini API (`gemini-2.5-flash`) — SOLO date parsing (rephrase eliminato, template da DB) |
| Calendario | Google Calendar API (service account JSON) |
| Messaggistica | WhatsApp Business API (Meta Cloud API v21.0) |
| Server | `bot.lecercleclub.it` su Plesk |
| Timezone | `Europe/Rome` |

---

## Architettura — Principio Fondamentale

**L'AI NON controlla la logica.** La macchina a stati è deterministica. Gemini viene invocata solo per:
1. **Riformulare i messaggi template** — per variare il tono (fallback al testo fisso se Gemini fallisce)
2. **Parsare date in linguaggio naturale** — solo se il parser locale deterministico fallisce

```
WhatsAppController          → Sottile: valida webhook, estrae input, delega
    │
    └─▶ BotOrchestrator     → Coordina: sessione, side-effects, invio messaggi
            │
            ├─▶ StateHandler        → Macchina a stati DETERMINISTICA (tutta la logica)
            │       │
            │       └─▶ TextGenerator   → Template da DB (BotMessage) + parsing date (Gemini)
            │                              + classificazione AI input (Gemini fallback per bottoni)
            │
            ├─▶ BotFlowState (DB)   → Configurazione stati: bottoni, transizioni, messaggi
            │                          Editabile da pannello admin (/panel/flusso)
            │
            ├─▶ CalendarService     → Google Calendar: verifica slot + crea/elimina eventi
            ├─▶ UserProfileService  → Persistenza utente su DB (con stima ELO)
            └─▶ WhatsAppService     → Invio messaggi WhatsApp (testo + pulsanti)
```

---

## Mappa File del Progetto

```
app/
├── Console/Commands/
│   ├── SendMatchResultRequests.php     ← Scheduler: chiedi risultato 1h dopo partita
│   └── SendBookingReminders.php        ← Scheduler: promemoria prenotazioni (configurabile)
├── Filament/                           ← Legacy admin (sostituito da React SPA)
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── AuthController.php      ← Login/logout/me (session-based, solo admin)
│   │   │   ├── DashboardController.php ← Stats + weekly chart
│   │   │   ├── BookingController.php   ← CRUD prenotazioni + calendar + today
│   │   │   ├── UserController.php      ← CRUD giocatori + search autocomplete
│   │   │   ├── BotSessionController.php← Lista sessioni + chat history
│   │   │   ├── MatchResultController.php← Risultati partite
│   │   │   ├── PricingRuleController.php← CRUD regole prezzi
│   │   │   ├── BotMessageController.php← CRUD messaggi bot (template configurabili)
│   │   │   ├── BotFlowStateController.php← Flusso stati: lista + aggiorna bottoni
│   │   │   └── SettingsController.php  ← Lettura/scrittura bot_settings
│   │   └── WhatsAppController.php      ← Webhook verify + handle (risponde sempre 200)
│   ├── Middleware/
│   │   └── EnsureIsAdmin.php           ← Verifica is_admin sull'utente
│   └── Resources/                      ← API Resources (JSON transform)
├── Models/
│   ├── BotSession.php                  ← Sessione bot (phone, state, data JSON)
│   ├── Booking.php                     ← Prenotazione campo
│   ├── MatchInvitation.php             ← Invito matchmaking (booking_id, receiver_id, status)
│   ├── MatchResult.php                 ← Risultato partita + ELO tracking
│   ├── EloHistory.php                  ← Storico variazioni ELO
│   ├── Feedback.php                    ← Feedback utenti (rating 1-5 + commento)
│   ├── BotSetting.php                  ← Settings chiave-valore (reminder, ecc.)
│   ├── BotMessage.php                  ← Template messaggi bot (chiave→testo, con cache)
│   ├── BotFlowState.php                ← Configurazione stati flusso (bottoni, transizioni, con cache)
│   └── User.php                        ← Modello utente con profilo tennis
└── Services/
    ├── CalendarService.php             ← Google Calendar API (checkUserRequest, createEvent, deleteEvent)
    ├── GeminiService.php               ← Client Gemini (generate)
    ├── WhatsAppService.php             ← Client WhatsApp (sendText, sendButtons)
    └── Bot/
        ├── BotState.php                ← Enum con tutti gli stati e transizioni validate
        ├── BotPersona.php              ← Nomi tennisti + saluti (greetingNew, greetingReturning)
        ├── BotResponse.php             ← DTO risposta (messaggio, stato, pulsanti, flag side-effect)
        ├── BotOrchestrator.php         ← Coordinatore: DB tx, side-effects, invio messaggi
        ├── StateHandler.php            ← Macchina a stati (tutti gli handle*)
        ├── TextGenerator.php           ← Template da DB (BotMessage) + parseDateTime (Gemini fallback)
        ├── UserProfileService.php      ← saveFromBot(): crea/aggiorna User con stima ELO
        └── EloService.php              ← Calcolo ELO dopo partita
frontend/                               ← React SPA (monorepo)
├── src/
│   ├── components/
│   │   ├── layout/                     ← AppLayout, Sidebar, Header
│   │   ├── auth/                       ← RequireAuth wrapper
│   │   └── ui/                         ← Shadcn/UI + FormDialog, PlayerSearch
│   ├── pages/
│   │   ├── login.tsx                   ← Login admin
│   │   ├── dashboard.tsx               ← Stats, grafico 7gg, prenotazioni oggi
│   │   ├── calendario.tsx              ← Vista giorno/settimana con blocchi
│   │   ├── prenotazioni.tsx            ← CRUD prenotazioni con filtri
│   │   ├── giocatori.tsx               ← CRUD giocatori con sort/search
│   │   ├── sessioni.tsx                ← Lista sessioni + chat view
│   │   ├── match.tsx                   ← Risultati + classifica ELO
│   │   ├── messaggi.tsx                ← CRUD messaggi bot raggruppati per categoria
│   │   ├── flusso.tsx                  ← Flow editor: stati, bottoni, transizioni, AI fallback
│   │   └── impostazioni.tsx            ← Pricing rules CRUD + config reminder
│   ├── hooks/
│   │   ├── use-api.ts                  ← Fetch wrapper con auth
│   │   └── use-auth.tsx                ← AuthProvider + useAuth
│   └── types/api.ts                    ← TypeScript types allineati a DB
├── vite.config.ts                      ← base: /panel/, build → public/panel/
└── public/.htaccess                    ← SPA routing per Apache
```

---

## Schema Database

### Tabella `bot_sessions`

```sql
id          BIGINT PK AUTO_INCREMENT
phone       VARCHAR(20) UNIQUE NOT NULL
state       VARCHAR(30) NOT NULL DEFAULT 'NEW'
data        JSON
created_at  TIMESTAMP
updated_at  TIMESTAMP
```

**Campo `data` JSON — chiavi usate:**

| Chiave | Tipo | Descrizione |
|---|---|---|
| `persona` | string | Nome tennista per questa sessione |
| `history` | array | `[{role: "user"\|"model", content: "..."}]` — max 40 messaggi |
| `profile` | object | Dati onboarding: `name`, `is_fit`, `fit_rating`, `self_level`, `age`, `slot` |
| `booking_type` | string | `con_avversario` / `matchmaking` / `sparapalline` |
| `requested_date` | string | Y-m-d |
| `requested_time` | string | H:i |
| `requested_friendly` | string | Es. "sabato 4 aprile alle 18:00" |
| `requested_raw` | string | Input originale dell'utente |
| `calendar_result` | object | `{available: bool, alternatives: [...]}` |
| `alternatives` | array | Slot alternativi proposti |
| `payment_method` | string | `online` / `in_loco` |
| `editing_booking_id` | int | ID prenotazione in modifica |
| `selected_booking_id` | int | ID prenotazione selezionata in gestione |
| `bookings_list` | array | Lista prenotazioni caricate per gestione |
| `update_field` | string | Campo in modifica nel profilo: `fit`/`classifica`/`livello`/`slot` |
| `pending_booking_id` | int | ID booking creato durante matchmaking (lato challenger) |
| `opponent_name` | string | Nome avversario trovato (lato challenger) |
| `opponent_phone` | string | Telefono avversario trovato (lato challenger) |
| `invited_by_phone` | string | Telefono del challenger (lato avversario) |
| `invited_by_name` | string | Nome del challenger (lato avversario) |
| `invited_slot` | string | Slot proposto nell'invito (lato avversario) |
| `invited_booking_id` | int | ID booking dell'invito (lato avversario) |

### Tabella `users`

```sql
id                  BIGINT PK
name                VARCHAR
email               VARCHAR UNIQUE  -- wa_XXXXXXXXXX@lecercleclub.bot
phone               VARCHAR UNIQUE
password            VARCHAR (hashed, random)
is_fit              BOOLEAN
fit_rating          VARCHAR NULLABLE   -- NC, 4.1, 3.3, ecc.
self_level          VARCHAR NULLABLE   -- neofita / dilettante / avanzato  (VARCHAR, non int!)
age                 SMALLINT NULLABLE
elo_rating          INTEGER DEFAULT 1200
matches_played      INTEGER
matches_won         INTEGER
is_elo_established  BOOLEAN
preferred_slots     JSON               -- ["mattina"] / ["pomeriggio"] / ["sera"]
```

### Tabella `bookings`

```sql
id                    BIGINT PK
player1_id            FK users (challenger o prenotante)
player2_id            FK users NULLABLE (avversario)
booking_date          DATE
start_time            TIME
end_time              TIME
price                 DECIMAL(8,2)
is_peak               BOOLEAN
status                VARCHAR  -- pending_match / confirmed / cancelled
gcal_event_id         VARCHAR NULLABLE
stripe_payment_link_p1/p2  VARCHAR NULLABLE
payment_status_p1/p2  VARCHAR DEFAULT 'pending'
```

### Tabella `match_invitations`

```sql
id           BIGINT PK
booking_id   FK bookings
receiver_id  FK users (avversario)
status       VARCHAR  -- pending / accepted / refused
```

### Tabella `bot_messages`

```sql
key          VARCHAR PK           -- es. 'chiedi_fit', 'menu_ritorno'
text         TEXT                  -- testo del messaggio (con variabili {name}, {slot}, ecc.)
category     VARCHAR(50)           -- raggruppamento: onboarding, menu, prenotazione, conferma, gestione, profilo, matchmaking, risultati, feedback, promemoria, errore
description  VARCHAR NULLABLE      -- descrizione per l'admin (es. "Chiedi se tesserato FIT")
created_at   TIMESTAMP
updated_at   TIMESTAMP
```

Gestita tramite `BotMessage` model con cache (1h TTL). Fallback hardcoded in `TextGenerator::FALLBACKS` se il record DB mancasse. Configurabile da pannello admin `/panel/messaggi`.

### Tabella `bot_flow_states`

```sql
state         VARCHAR(30) PK        -- nome stato (es. 'ONBOARD_FIT', 'MENU')
type          VARCHAR(10)            -- 'simple' (configurabile) | 'complex' (logica custom)
message_key   VARCHAR(100)           -- FK bot_messages
fallback_key  VARCHAR(100) NULLABLE  -- FK bot_messages (input non capito)
buttons       JSON NULLABLE          -- [{label, target_state, value?, side_effect?}]
category      VARCHAR(50)            -- raggruppamento UI
description   VARCHAR NULLABLE
sort_order    SMALLINT DEFAULT 0
```

Gestita tramite `BotFlowState` model con cache (1h TTL). I bottoni configurati qui vengono letti dal `StateHandler` (con fallback hardcoded). Configurabile da pannello admin `/panel/flusso`.

**Tipo `simple`**: bottoni e transizioni completamente editabili da pannello. Se l'input non matcha nessun bottone, la **classificazione AI** (Gemini) tenta di capire quale bottone l'utente intendeva.

**Tipo `complex`**: logica nel codice (parsing date, calcoli prezzi, API Calendar), ma le label dei bottoni e i messaggi sono comunque editabili.

---

## Macchina a Stati — Enum `BotState`

### Tutti gli stati

```
── Onboarding ──────────────────────────────
NEW                 Primo contatto
ONBOARD_NOME        In attesa del nome
ONBOARD_FIT         In attesa conferma tesseramento FIT
ONBOARD_CLASSIFICA  In attesa classifica FIT
ONBOARD_LIVELLO     In attesa livello autodichiarato
ONBOARD_ETA         In attesa età
ONBOARD_SLOT_PREF   In attesa fascia oraria preferita
ONBOARD_COMPLETO    Registrazione completata

── Menu ────────────────────────────────────
MENU                Menu principale

── Prenotazione ────────────────────────────
SCEGLI_QUANDO       In attesa data/ora
SCEGLI_DURATA       In attesa durata (mostra tariffe)
VERIFICA_SLOT       Calendar check in corso
PROPONI_SLOT        Slot proposto, in attesa conferma
CONFERMA            Riepilogo, in attesa conferma pagamento
PAGAMENTO           Pagamento online in corso
CONFERMATO          Prenotazione confermata

── Risultati & Feedback ────────────────────
INSERISCI_RISULTATO In attesa risultato (vinto/perso/non giocata)
FEEDBACK            In attesa rating 1-5
FEEDBACK_COMMENTO   In attesa commento opzionale

── Matchmaking ─────────────────────────────
ATTESA_MATCH        Challenger in attesa risposta avversario
RISPOSTA_MATCH      Avversario in attesa di rispondere all'invito

── Gestione prenotazioni ───────────────────
GESTIONE_PRENOTAZIONI  Lista prenotazioni mostrata
AZIONE_PRENOTAZIONE    Azione su prenotazione selezionata

── Modifica profilo ────────────────────────
MODIFICA_PROFILO    Scelta campo da modificare
MODIFICA_RISPOSTA   In attesa nuovo valore
```

### Transizioni valide (`BotState::allowedTransitions()`)

```
NEW                  → ONBOARD_NOME
ONBOARD_NOME         → ONBOARD_FIT
ONBOARD_FIT          → ONBOARD_CLASSIFICA | ONBOARD_LIVELLO | ONBOARD_NOME (indietro)
ONBOARD_CLASSIFICA   → ONBOARD_ETA | ONBOARD_FIT (indietro)
ONBOARD_LIVELLO      → ONBOARD_ETA | ONBOARD_FIT (indietro)
ONBOARD_ETA          → ONBOARD_SLOT_PREF | ONBOARD_CLASSIFICA | ONBOARD_LIVELLO (indietro)
ONBOARD_SLOT_PREF    → ONBOARD_COMPLETO | ONBOARD_ETA (indietro)
ONBOARD_COMPLETO     → MENU | SCEGLI_QUANDO | ATTESA_MATCH

MENU                 → SCEGLI_QUANDO | ATTESA_MATCH | GESTIONE_PRENOTAZIONI | MODIFICA_PROFILO | RISPOSTA_MATCH
SCEGLI_QUANDO        → VERIFICA_SLOT | MENU | GESTIONE_PRENOTAZIONI
VERIFICA_SLOT        → PROPONI_SLOT | MENU | GESTIONE_PRENOTAZIONI
PROPONI_SLOT         → CONFERMA | SCEGLI_QUANDO | MENU | GESTIONE_PRENOTAZIONI
CONFERMA             → PAGAMENTO | CONFERMATO | SCEGLI_QUANDO | MENU | GESTIONE_PRENOTAZIONI
PAGAMENTO            → CONFERMATO | MENU | GESTIONE_PRENOTAZIONI
CONFERMATO           → MENU | GESTIONE_PRENOTAZIONI

ATTESA_MATCH         → SCEGLI_QUANDO | MENU | GESTIONE_PRENOTAZIONI | RISPOSTA_MATCH
RISPOSTA_MATCH       → CONFERMATO | MENU

GESTIONE_PRENOTAZIONI → AZIONE_PRENOTAZIONE | MENU
AZIONE_PRENOTAZIONE  → SCEGLI_QUANDO | MENU

MODIFICA_PROFILO     → MODIFICA_RISPOSTA | MENU
MODIFICA_RISPOSTA    → MENU | MODIFICA_RISPOSTA
```

**Regola ferrea**: `BotState::transitionTo($target)` restituisce il target solo se la transizione è dichiarata, altrimenti lo stato rimane invariato. Non esistono salti accidentali.

---

## Flussi Conversazionali

### Flusso 1 — Onboarding (nuovo utente)

Il bot assegna una persona casuale alla sessione. Primo messaggio entra in stato NEW.

1. **NEW → ONBOARD_NOME**: Saluto con nome tennista, chiede il nome
2. **ONBOARD_NOME → ONBOARD_FIT**: Salva nome (Title Case), chiede tesseramento FIT
   - Pulsanti: `["Sì, sono tesserato", "Non sono tesserato"]`
3a. **ONBOARD_FIT → ONBOARD_CLASSIFICA** (se FIT): chiede classifica (4.1, NC, ecc.)
3b. **ONBOARD_FIT → ONBOARD_LIVELLO** (se non FIT): chiede livello
   - Pulsanti: `["Neofita", "Dilettante", "Avanzato"]`
4. **→ ONBOARD_ETA**: chiede età
5. **→ ONBOARD_SLOT_PREF**: chiede fascia oraria preferita
   - Pulsanti: `["Mattina", "Pomeriggio", "Sera"]`
6. **→ ONBOARD_COMPLETO**: salva profilo nel DB (`UserProfileService::saveFromBot`), mostra menu
   - Pulsanti: `["Ho già un avversario", "Trovami avversario", "Sparapalline"]`

**Navigazione indietro**: keyword `indietro` (o sinonimi) durante l'onboarding riporta allo step precedente. Non funziona in ONBOARD_NOME (non c'è un passo precedente).

**Validazioni input:**
- Nome: solo lettere/spazi/apostrofi, 2–60 caratteri, Title Case automatico
- FIT: negativo prima del positivo (evita che "non sono tesserato" venga letto come sì)
- Classifica FIT: `4.1`–`1.1`, `NC`, anche forme verbali ("terza categoria" → `3.1`)
- Livello: mappa sinonimi (principiante→neofita, intermedio→dilettante, esperto→avanzato)
- Età: primo numero nell'input, range 5–99
- Fascia oraria: mappa sinonimi (mattino→mattina, serale→sera, tardi→sera, dopo cena→sera)

Input non valido → stessa domanda ripetuta, stato invariato.

---

### Flusso 2 — Prenotazione campo (con avversario o sparapalline)

Parte dal MENU dopo aver scelto "Ho già un avversario" o "Sparapalline".
`booking_type` nella sessione = `con_avversario` o `sparapalline`.

1. **SCEGLI_QUANDO**: chiede giorno e ora in linguaggio naturale
2. **VERIFICA_SLOT**:
   - L'orchestrator invia subito il messaggio "verifico..." (commit + send fuori tx)
   - Poi esegue `CalendarService::checkUserRequest()`
   - Ri-processa `VERIFICA_SLOT` con i risultati in `calendar_result`
3. **PROPONI_SLOT**:
   - Slot libero → mostra slot, pulsanti: `["Sì, prenota", "No, cambia orario"]`
   - Slot occupato con alternative → mostra max 3 alternative come pulsanti
   - Nessuna alternativa → torna a SCEGLI_QUANDO
4. **CONFERMA**: riepilogo slot
   - Pulsanti: `["Paga online", "Pago di persona", "Annulla"]`
   - "Paga online" → PAGAMENTO (con `payment_required` flag)
   - "Pago di persona" → CONFERMATO (crea Booking + evento Calendar)
   - "Annulla" → MENU
5. **CONFERMATO**: conferma, qualunque messaggio successivo torna al MENU

**Modifica prenotazione**: se `editing_booking_id` è presente in sessione, `createBooking()` cancella la vecchia prenotazione (Calendar + DB) prima di crearne una nuova.

---

### Flusso 3 — Matchmaking

Parte dal MENU con "Trovami avversario". `booking_type = matchmaking`.

1. **SCEGLI_QUANDO** → **VERIFICA_SLOT** → **PROPONI_SLOT**: identico al flusso 2
2. **CONFERMA**: pulsanti diversi → `["Cerca avversario", "Annulla"]`
   - "Cerca avversario" o qualsiasi conferma → ATTESA_MATCH + flag `matchmakingToSearch`
3. **`BotOrchestrator::triggerMatchmaking()`**:
   - Cerca avversario con `elo_rating` ±200, diverso dal challenger, con phone
   - Se **non trovato**: invia messaggio "nessun avversario", torna a MENU
   - Se **trovato**:
     - Crea `Booking` con `status = pending_match`, `player1_id = challenger`, `player2_id = opponent`
     - Crea `MatchInvitation` con `status = pending`
     - Aggiorna (o crea) sessione avversario: stato `RISPOSTA_MATCH`, salva `invited_*` data
     - Invia WhatsApp all'avversario: "Ciao X! Y ti sfida il [slot]. Accetti?" + `["Accetta", "Rifiuta"]`
4. **Challenger** rimane in **ATTESA_MATCH** fino alla risposta. Può annullare con "annulla" → MENU.
5. **Avversario** è in **RISPOSTA_MATCH**:
   - "Accetta" → `withMatchAccepted(true)` → `confirmMatch()`:
     - Aggiorna MatchInvitation → `accepted`
     - Crea evento Google Calendar
     - Aggiorna Booking → `confirmed`
     - Notifica challenger: "X ha accettato! Ci vediamo il [slot]. ✅" + pulsanti menu
     - Challenger → stato CONFERMATO
   - "Rifiuta" → `withMatchRefused(true)` → `refuseMatch()`:
     - Aggiorna MatchInvitation → `refused`
     - Aggiorna Booking → `cancelled`
     - Notifica challenger: "X non è disponibile. Cerca un altro avversario?"
     - Challenger → stato MENU
   - Input non riconosciuto → ripropone l'invito

---

### Flusso 4 — Gestione prenotazioni

Attivabile da qualsiasi stato non-onboarding con la keyword `prenotazioni`.

1. **`handleMostraPrenotazioni()`**: carica le prossime 3 prenotazioni (`confirmed`/`pending_match`, da oggi in poi), le mostra come pulsanti (label: `Lun 6 apr 18:00`)
2. **GESTIONE_PRENOTAZIONI**: attende selezione → cerca corrispondenza per label/orario
3. **AZIONE_PRENOTAZIONE**: mostra la prenotazione selezionata
   - Pulsanti: `["Modifica orario", "Cancella", "Torna al menu"]`
   - "Modifica orario" → salva `editing_booking_id`, va a SCEGLI_QUANDO
   - "Cancella" → `withBookingToCancel(true)` → `cancelBooking()`: elimina evento Calendar + status = `cancelled`
   - "Torna al menu" → MENU

---

### Flusso 5 — Modifica profilo

Attivabile da qualsiasi stato non-onboarding con la keyword `profilo`.

1. **MODIFICA_PROFILO**: chiede cosa modificare
   - Pulsanti: `["Stato FIT", "Livello gioco", "Fascia oraria"]`
2. **MODIFICA_RISPOSTA**: raccoglie la risposta, valida, salva tramite `withProfileToSave()`
   - Riusa gli stessi parser dell'onboarding (parseClassificaFit, parseLivello, parseFasciaOraria)
   - Torna al MENU dopo il salvataggio

---

### Flusso 6 — Risultati partita

Attivato automaticamente dallo scheduler `bot:send-result-requests` (ogni 15 min, 1h dopo fine partita).

1. **Scheduler** crea `MatchResult` e imposta sessione avversari a `INSERISCI_RISULTATO`
2. **INSERISCI_RISULTATO**: attende input
   - Pulsanti: `["Ho vinto", "Ho perso", "Non giocata"]`
   - Rileva keyword: "vinto", "perso", "non giocata", "annullata"
   - Estrae punteggio opzionale: regex `\b(\d{1,2})[-\/](\d{1,2})\b`
   - Flag: `withMatchResultToSave(true)` → `processMatchResult()` nell'orchestrator
3. **processMatchResult()**: aggiorna MatchResult per il ruolo (player1/player2)
   - Se entrambi confermati → `finalizeMatchResult()` → `EloService::processResult()`
   - Se discordanza → notifica entrambi, admin verifica
   - Se no_show → booking completato, nessun ELO
4. **Dopo il risultato** → transita automaticamente a FEEDBACK (rating)

---

### Flusso 7 — Feedback

Attivabile con keyword "feedback" o automaticamente dopo inserimento risultato partita.

1. **FEEDBACK**: chiede rating 1-5
   - Pulsanti: `["1", "2", "3", "4", "5"]`
   - Parser: numeri, parole (uno-cinque), emoji stelle
2. **FEEDBACK_COMMENTO**: chiede commento opzionale
   - "no", "skip", "niente" → salva senza commento
   - Qualsiasi altro testo → salva come commento
3. Flag: `withFeedbackToSave(true)` → `saveFeedback()` nell'orchestrator
4. Salva su tabella `feedbacks` con tipo, rating, contenuto, link user/booking

---

### Flusso 8 — Promemoria prenotazioni

Scheduler `bot:send-reminders` (ogni 15 min). Configurabile da pannello admin.

1. Legge settings da tabella `bot_settings` (chiave `reminders`)
2. Per ogni slot configurato (es. 24h prima, 2h prima):
   - Trova prenotazioni nella finestra temporale (±8 min)
   - Invia WhatsApp a player1 e player2
   - Cache 48h per evitare duplicati
3. Template: `reminder_giorno_prima` (≥12h) o `reminder_ore_prima` (<12h)

---

## Parole Chiave Globali (fuori dall'onboarding)

Intercettate all'inizio di `StateHandler::handle()` prima della macchina a stati:

| Keyword | Azione |
|---|---|
| `menu`, `home`, `aiuto`, `help`, `start`, `ricomincia`, `0`, `torna al menu` | → MENU con pulsanti |
| `prenotazioni`, `mie prenotaz`, `booking` | → mostra lista prenotazioni |
| `feedback`, `valuta`, `vota`, `recensione`, `opinione` | → FEEDBACK (rating 1-5) |
| `profilo`, `modifica profilo`, `aggiorna profilo`, `impostazioni` | → MODIFICA_PROFILO |

Durante l'onboarding: keyword `indietro`, `back`, `torna`, `annulla`, `precedente`, `torna indietro` → step precedente.

---

## Vincoli WhatsApp Business API

- **Pulsanti**: massimo **3** per messaggio. Se 4+ opzioni, usare testo libero.
- **Label pulsante**: massimo **20 caratteri** — verranno troncati altrimenti. Verificare sempre.
- Metodi: `sendText(phone, message)` e `sendButtons(phone, message, buttons[])`.
- Il controller risponde **sempre 200** a Meta, anche in caso di errore — per evitare retry infiniti.

---

## Template Messaggi (tutti i template attivi)

I messaggi sono salvati nella tabella `bot_messages` e configurabili dal pannello admin (`/panel/messaggi`).
`TextGenerator::rephrase()` legge direttamente dal DB (con cache 1h), **senza riformulazione AI**.
Fallback hardcoded in `TextGenerator::FALLBACKS` se il record DB mancasse.

```
Onboarding
  nome_non_valido         Scusa, non ho capito il tuo nome. Puoi ripetermelo?
  chiedi_fit              Piacere {name}! Sei tesserato FIT?
  fit_non_capito          Scusa, non ho capito. Sei tesserato FIT oppure no?
  chiedi_classifica       Ottimo! Qual è la tua classifica FIT? (es. 4.1, 3.3, NC)
  classifica_non_valida   Non ho riconosciuto la classifica. Prova con 4.1, 3.3 o NC.
  chiedi_livello          Nessun problema! Come definiresti il tuo livello?
  livello_non_valido      Non ho capito. Scegli tra Neofita, Dilettante o Avanzato.
  chiedi_eta              Quanti anni hai?
  eta_non_valida          Scusa, dimmi la tua età con un numero (es. 30).
  chiedi_fascia_oraria    Ultima cosa: quando preferisci giocare di solito?
  fascia_non_valida       Non ho capito. Preferisci mattina, pomeriggio o sera?
  registrazione_completa  Ottimo {name}, sei nel sistema! 🎾 Scrivi "menu" per il menu principale.
  indietro_onboarding     Nessun problema, torniamo al passo precedente.
  chiedi_nome_nuovo       Come ti chiami?

Menu
  menu_non_capito         Scusa, non ho capito. Scegli un'opzione o scrivi "prenotazioni".
  menu_ritorno            Ci sono per te! Cosa vuoi fare? Puoi scrivere "prenotazioni" per le tue prenotazioni.

Prenotazione
  chiedi_quando           Quando vorresti giocare? Dimmi giorno e ora (es. domani alle 18).
  chiedi_quando_match     Quando saresti disponibile per una partita? Dimmi giorno e ora.
  chiedi_quando_sparapalline  Quando vorresti usare lo sparapalline? Dimmi giorno e ora.
  data_non_capita         Non ho capito. Prova con "domani alle 17" o "sabato pomeriggio".
  verifico_disponibilita  Un attimo, verifico la disponibilità... ⏳
  slot_disponibile        Ottima notizia! {slot} è libero. Confermo la prenotazione?
  slot_non_disponibile    Purtroppo quell'orario non è disponibile. Ho trovato queste alternative:
  nessuna_alternativa     Mi dispiace, nessuno slot libero quel giorno. Prova un altro giorno?
  proposta_non_capita     Non ho capito. Vuoi prenotare questo slot oppure cambiare orario?

Conferma & Pagamento
  riepilogo_prenotazione  Riepilogo: prenotazione per {slot}. Come vuoi procedere?
  scegli_pagamento        Vuoi pagare online o di persona?
  conferma_non_capita     Scusa, non ho capito. Vuoi confermare, pagare online, o annullare?
  prenotazione_annullata  Prenotazione annullata. Nessun problema! Cosa vuoi fare?
  link_pagamento          Ecco il link per il pagamento. Confermata al completamento!
  prenotazione_confermata Prenotazione confermata per {slot}! ✅ Ti aspettiamo!

Matchmaking
  cerca_avversario        Perfetto! Cerco un avversario per {slot}. Ti scrivo appena lo trovo! 🔍
  matchmaking_attesa      Sto cercando un avversario adatto a te. Ti avviso appena trovo qualcuno! 🔍
  nessun_avversario       Nessun avversario disponibile per questo slot. Prova un altro orario?
  invito_match            Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Accetti?
  match_accettato_challenger  {opponent_name} ha accettato! Prenotazione confermata per {slot}. ✅
  match_rifiutato_challenger  {opponent_name} non è disponibile. Cerca un altro avversario?
  match_accettato_opponent    Perfetto! Hai accettato. Ci vediamo il {slot}! 🎾
  match_rifiutato_opponent    Ok, sfida rifiutata. A presto al circolo! 🎾

Gestione prenotazioni
  nessuna_prenotazione        Non hai prenotazioni attive al momento. Cosa vuoi fare?
  scegli_prenotazione         Ecco le tue prossime prenotazioni. Quale vuoi gestire?
  azione_prenotazione         Prenotazione: {slot}. Cosa vuoi fare?
  prenotazione_cancellata_ok  Prenotazione annullata. A presto in campo! 🎾 Cosa vuoi fare?
  prenotazione_modifica_quando  Ok! Quando vorresti spostare? Dimmi giorno e orario.

Modifica profilo
  modifica_profilo_scelta  Cosa vuoi modificare nel tuo profilo?
  profilo_aggiornato       Perfetto, profilo aggiornato! Cosa vuoi fare?

Errori
  errore_generico          Scusa, c'è stato un problema. Riproviamo: quando vorresti giocare?
```

---

## Google Calendar

- **Credenziali**: service account JSON in `storage/google-calendar-credentials.json`
- **Calendar ID**: da `.env` → `GOOGLE_CALENDAR_ID`
- **Orari operativi**: 08:00–22:00
- **Durata slot**: 1 ora (default)
- **Timezone**: `Europe/Rome`

### `checkUserRequest(string $query)` → array
1. Parsa data/ora dal formato `YYYY-MM-DD HH:MM`
2. Cerca eventi sovrapposti via freebusy API
3. Se libero: `{available: true}`
4. Se occupato: cerca tutti gli slot liberi (08:00–22:00) nella giornata, massimo 5, con prezzo stimato

### `createEvent(summary, description, startTime, endTime)` → GoogleEvent
- Summary per prenotazione normale: `"{Tipo} - {Nome utente}"`
- Summary per matchmaking: `"Partita singolo - {Player1} vs {Player2}"`
- Description: giocatore(i), telefono(i), tipo, pagamento, "Prenotato via: WhatsApp Bot"

### `deleteEvent(string $eventId)` → void
- Usato da: `cancelBooking()`, modifica prenotazione (cancella il vecchio evento prima di crearne uno nuovo)

### Prezzi (placeholder, non da pricing_rules)
- Mattina 08:00–14:00 → €20
- Pomeriggio 14:00–18:00 → €25
- Sera 18:00–22:00 → €30

---

## Parsing Date — Parser Locale

In `TextGenerator::parseDateTimeLocal()`. Ha la priorità assoluta su Gemini.

| Input | Data | Ora |
|---|---|---|
| `domani alle 17` | domani | 17:00 |
| `domani 15` | domani | 15:00 |
| `domani alle 9:30` | domani | 09:30 |
| `oggi pomeriggio` | oggi | 15:00 |
| `sabato mattina` | prossimo sabato | 09:00 |
| `lunedì alle 18` | prossimo lunedì | 18:00 |
| `28 marzo` | 28 marzo (anno corrente/prossimo) | null |
| `28/03` | 28 marzo | null |
| `dopodomani ore 10` | dopodomani | 10:00 |

Fasce orarie: mattina=09:00, pranzo=13:00, pomeriggio=15:00, sera=19:00.
Date passate vengono spostate all'anno successivo automaticamente.
Solo se il parser locale fallisce → Gemini come fallback (JSON strutturato).

---

## BotResponse — Flag Side-Effect

Il `StateHandler` segnala effetti collaterali tramite flag sul DTO `BotResponse`. L'orchestrator li esegue.

| Flag / Metodo | Azione in BotOrchestrator |
|---|---|
| `withCalendarCheck(true)` | Commit tx, invia messaggio "verifico...", ri-apre tx, chiama calendar, ri-processa |
| `withBookingToCreate(true)` | `createBooking()`: crea Booking DB + evento Calendar |
| `withBookingToCancel(true)` | `cancelBooking()`: elimina evento Calendar + status=cancelled |
| `withProfileToSave($array)` | `UserProfileService::saveFromBot()`: crea/aggiorna User |
| `withMatchmakingSearch(true)` | `triggerMatchmaking()`: trova avversario, crea Booking+Invitation, invia invito WA |
| `withMatchAccepted(true)` | `confirmMatch()`: crea gcal event, booking=confirmed, notifica challenger |
| `withMatchRefused(true)` | `refuseMatch()`: booking=cancelled, notifica challenger |
| `withMatchResultToSave(true)` | `processMatchResult()`: aggiorna MatchResult, se entrambi confermati → ELO |
| `withFeedbackToSave(true)` | `saveFeedback()`: salva rating + commento su tabella feedbacks |
| `withPaymentRequired(true)` | Attualmente usato come flag — pagamento online non ancora integrato |

---

## Conversione Classifica/Livello → ELO (UserProfileService)

| Classifica FIT | ELO | Livello autodichiarato | ELO |
|---|---|---|---|
| NC | 1100 | Neofita | 1000 |
| 4.6 | 1050 | Dilettante | 1200 |
| 4.5 | 1100 | Avanzato | 1400 |
| 4.4 | 1150 | | |
| 4.3 | 1200 | | |
| 4.2 | 1250 | | |
| 4.1 | 1300 | | |
| 3.5 | 1350 | | |
| 3.4 | 1400 | | |
| 3.3 | 1450 | | |
| 3.2 | 1500 | | |
| 3.1 | 1550 | | |
| 2.8 | 1600 | | |
| 2.1 | 1950 | | |
| 1.1 | 2100 | | |

Default: 1200. Classifica FIT ha priorità sul livello autodichiarato.

---

## Gestione Errori

- Ogni chiamata esterna (Gemini, Calendar, WhatsApp) è wrappata in `try/catch`
- **Gemini fallisce** → usa il template di fallback fisso (bot funziona comunque)
- **Calendar fallisce** → messaggio errore generico all'utente
- **WhatsApp send fallisce** → logga errore, il flusso continua
- **Errore catastrofico** → "Scusa, ho avuto un problema tecnico. Riprova tra qualche istante! 🙏"
- **DB transaction** copre tutta l'operazione. Fallimento → rollback + messaggio di errore.
- Il controller risponde **sempre 200** a Meta (evita retry del webhook).

---

## Variabili d'Ambiente

```env
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_TOKEN=...
WHATSAPP_VERIFY_TOKEN=courtly_webhook_2026
GEMINI_KEY=...
GEMINI_MODEL=gemini-2.5-flash
GOOGLE_CALENDAR_CREDENTIALS=/path/to/google-calendar-credentials.json
GOOGLE_CALENDAR_ID=...@group.calendar.google.com
APP_TIMEZONE=Europe/Rome
```

Le variabili `.env` vengono lette **SOLO** nei file `config/*.php`. Nel codice si usa sempre `config('services.whatsapp.api_token')`, mai `env()` direttamente.

---

## Filament Admin Panel

### Pannello Generale

- Path: `/admin`, primary color: **Amber**
- Auto-discovery: `app/Filament/Resources`, `app/Filament/Pages`, `app/Filament/Widgets`
- Auth: solo utenti con `is_admin = true` (`User::canAccessPanel()`)

### Pagina Calendario (`CalendarBookings`)

Pagina custom Livewire — il centro di gestione delle prenotazioni. Accessibile da `/admin/calendar-bookings`.

**Funzionalità:**
- **Vista giornaliera** con timeline 08:00–22:00 (80px per ora)
- **Vista settimanale** con 7 colonne affiancate (Lun–Dom), header cliccabile per tornare a vista giorno, scroll orizzontale su mobile
- **Toggle Giorno/Settimana** nell'header, navigazione prev/next adatta alla vista corrente
- **Navigazione**: pulsanti Oggi/Precedente/Successivo + strip settimanale (solo in vista giorno)
- **Statistiche**: totale prenotazioni, confermate, in attesa, incasso (contesto giorno o settimana)
- **Blocchi prenotazione** colorati per stato: verde (confermata), ambra (in attesa), azzurro (completata)
- **Indicatore tempo reale**: linea rossa con orario corrente, auto-scroll (in entrambe le viste)
- **Click-to-create**: click su slot vuoto → redirect a BookingResource/create con data/ora pre-compilate (snap a 30 min)
- **Drag & drop**: trascinamento prenotazioni su nuovi orari/giorni. Aggiorna DB + Google Calendar + prezzo (PricingRule). Ghost indicator durante il drag. Notifica Filament al completamento.
- **Filtri**: ricerca giocatore per nome (debounce 300ms) + toggle stato (Confermate/In attesa/Completate)
- **Slide-over dettaglio**: click su prenotazione → pannello laterale con giocatori, telefoni, prezzo, tariffa, stato pagamento, sync Google Calendar, link a modifica
- **Chiusura slide-over**: click overlay, tasto Escape, pulsante X
- **Badge navigazione**: mostra il numero di prenotazioni del giorno corrente nella sidebar
- **Empty state**: messaggio con invito a cliccare per creare
- **URL persistente**: `selectedDate` sincronizzato via `#[Url]`

**Proprietà Livewire:**
- `$selectedDate` (string, #[Url]) — data selezionata
- `$viewMode` (string) — `day` o `week`
- `$filterPlayer` (string) — ricerca giocatore
- `$filterStatuses` (array) — stati attivi nel filtro
- `$selectedBooking` (?array) — dettaglio prenotazione aperta

**Computed Properties (#[Computed]):**
- `bookings` — prenotazioni filtrate del giorno selezionato
- `weekBookings` — prenotazioni filtrate della settimana, raggruppate per data (array di collection)
- `weekDays` — 7 giorni della settimana con conteggio prenotazioni (query singola)
- `formattedDate` — data/range in italiano (adattato a vista giorno/settimana)
- `stats` — totale, confermate, in attesa, incasso (contesto vista)
- `currentTimePosition` — posizione px linea "adesso"
- `todayColumnIndex` — indice colonna "oggi" in vista settimanale

**Metodi principali:**
- `moveBooking(id, newDate, newTime)` — drag & drop: aggiorna booking, prezzo, Google Calendar
- `createAtSlot(date, time)` — redirect a BookingResource/create con query params
- `selectBooking(id)` / `closeDetail()` — gestione slide-over
- `toggleStatus(status)` — toggle filtro stato
- `switchToDay(date)` — da vista settimanale a giornaliera su un giorno specifico

**Alpine.js (`calendarApp()`):**
- `scrollToNow()` — auto-scroll alla posizione corrente
- `clickToCreate(event, date)` — calcola orario dal click (snap 30 min), chiama `$wire.createAtSlot`
- `dragStart/dragOver/dragLeave/dragEnd/drop` — gestione HTML5 drag & drop con ghost indicator
- Ghost image trasparente custom, blocchi non-dragged diventano `opacity-40 pointer-events-none`

**BookingResource prefill:**
- La form di creazione legge `?date=` e `?time=` dalla query string
- `end_time` calcolato automaticamente come `time + 1 ora`

**File:**
- `app/Filament/Pages/CalendarBookings.php`
- `resources/views/filament/pages/calendar-bookings.blade.php`

### Dashboard (`/admin`)

La dashboard usa 4 widget custom auto-discovered da `app/Filament/Widgets/`:

| Widget | Tipo | Sort | Descrizione |
|---|---|---|---|
| `StatsOverview` | StatsOverviewWidget | 1 | 4 stat card: prenotazioni oggi (sparkline 7gg + trend%), incasso oggi (sparkline + trend%), giocatori totali (+nuovi settimana), match in attesa |
| `WeeklyBookingsChart` | ChartWidget (bar stacked) | 2 | Grafico a barre ultimi 7 giorni, stacked per stato (confermate/in attesa/completate). Colori: emerald/amber/sky. Query singola con groupBy(date, status) |
| `TodaySchedule` | TableWidget | 3 | Tabella prenotazioni di oggi (orario, giocatori, stato badge, prezzo, peak icon). Non paginata. Empty state personalizzato |
| `LatestUsers` | TableWidget | 4 | Ultimi 8 giocatori registrati (nome, telefono, FIT, livello badge, ELO, "registrato X fa"). Non paginata |

**Rimosso:** `FilamentInfoWidget` (branding Filament). **Mantenuto:** `AccountWidget` (profilo/logout).

### Resources

| Resource | Modello | Tipo | NavigationSort |
|---|---|---|---|
| UserResource | User | CRUD | 1 |
| BookingResource | Booking | CRUD (prefill da query params `?date=&time=`) | 3 |
| BotSessionResource | BotSession | Sola lettura (Infolist) | 2 |
| MatchResultResource | MatchResult | List + Edit | 4 |
| FeedbackResource | Feedback | List + View | 5 |
| PricingRuleResource | PricingRule | CRUD (reorderable) | 6 |

---

## React SPA — Admin Panel (`/panel`)

### Stack Frontend
- **Vite** + React 19 + TypeScript
- **Tailwind CSS v4** + Shadcn/UI (base-ui)
- **Recharts** per grafici
- **Lucide** per icone
- Build output: `public/panel/` con `.htaccess` per SPA routing
- URL: `https://bot.lecercleclub.it/panel/`

### Autenticazione
- Session-based (stessa sessione di Filament, no Sanctum)
- `POST /api/auth/login` → verifica credenziali + `is_admin`
- `GET /api/auth/me` → check sessione
- Middleware `auth` + `admin` su tutte le route `/api/admin/*`
- Frontend: `AuthProvider` + `RequireAuth` wrapper

### API Endpoints (`/api/admin/...`)

| Endpoint | Metodo | Descrizione |
|---|---|---|
| `/dashboard/stats` | GET | Stats: prenotazioni, incasso, trend, giocatori, pending |
| `/dashboard/weekly-chart` | GET | Grafico 7 giorni stacked per stato |
| `/bookings` | GET/POST | Lista paginata / Crea prenotazione (auto-prezzo) |
| `/bookings/{id}` | GET/PUT/DELETE | Dettaglio / Modifica / Annulla |
| `/bookings/today` | GET | Prenotazioni di oggi |
| `/bookings/calendar?from=&to=` | GET | Range date, raggruppate per giorno |
| `/users` | GET | Lista paginata con filtri e sort |
| `/users/search?q=` | GET | Autocomplete (max 10 risultati) |
| `/users/{id}` | GET/PUT/DELETE | Dettaglio / Modifica / Elimina |
| `/bot-sessions` | GET | Lista sessioni con chat history |
| `/match-results` | GET | Lista risultati partite |
| `/pricing-rules` | GET/POST | Lista / Crea regola |
| `/pricing-rules/{id}` | PUT/DELETE | Modifica / Elimina |
| `/settings` | GET | Tutte le impostazioni |
| `/settings/{key}` | GET/PUT | Leggi / Aggiorna setting |
| `/bot-messages` | GET | Lista messaggi bot raggruppati per categoria |
| `/bot-messages/{key}` | PUT | Aggiorna testo di un messaggio |
| `/bot-flow-states` | GET | Lista stati flusso raggruppati per categoria (con testi) |
| `/bot-flow-states/{state}` | PUT | Aggiorna bottoni/transizioni di uno stato |

### Tabella `bot_settings`
```sql
key        VARCHAR PRIMARY KEY
value      JSON
created_at TIMESTAMP
updated_at TIMESTAMP
```
Chiave `reminders`: `{enabled: bool, slots: [{hours_before: int, enabled: bool}]}`

### Scheduler Commands
| Comando | Frequenza | Descrizione |
|---|---|---|
| `bot:send-result-requests` | Ogni 15 min | Chiede risultato 1h dopo partita |
| `bot:retry-matchmaking` | Ogni 5 min | Riprova matchmaking per chi è in attesa |
| `bot:send-reminders` | Ogni 15 min | Promemoria prenotazioni (configurabile) |

---

## Regole di Sviluppo

1. **La logica di stato va SOLO in `StateHandler`**. Nessuna altra classe decide transizioni.
2. **L'AI va SOLO in `TextGenerator`** (date parsing + classificazione input bottoni). I messaggi vengono dalla tabella `bot_messages`, nessuna riformulazione AI. La classificazione AI è un fallback: se l'input non matcha nessun bottone, Gemini determina quale bottone l'utente intendeva.
3. **I side-effect** (Calendar, DB, WhatsApp) vengono segnalati con flag su `BotResponse` ed eseguiti dall'orchestrator. Mai dall'handler direttamente.
4. **Le transizioni** sono validate da `BotState::transitionTo()`. Transizioni non dichiarate vengono ignorate silenziosamente.
5. **Ogni template ha un fallback hardcoded** in `TextGenerator::FALLBACKS`. Il bot funziona anche senza DB (e senza Gemini).
6. **Il parser di date locale ha la priorità**. Gemini è fallback solo per input complessi.
7. **Max 3 pulsanti** per messaggio WhatsApp. Max 20 caratteri per label pulsante.
8. **Template corti** (< 100 caratteri idealmente). Template lunghi vengono troncati da Gemini.
9. **La transazione DB** copre tutta l'operazione di sessione per ogni messaggio in arrivo.
10. **I log** includono sempre il contesto: phone, input, stato corrente, errore.
11. **La lingua è sempre italiano**. Tutti i messaggi, i template, i log significativi.
12. **Dopo ogni implementazione**, aggiornare questo file con la nuova logica di business.
