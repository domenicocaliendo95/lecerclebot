# Le Cercle Tennis Club — Bot WhatsApp
Bibbia progetto — agg. 2026-04-06

## Identità
Bot WhatsApp per **Le Cercle Tennis Club** (San Gennaro Vesuviano, NA). Gestisce registrazione, prenotazione campi, matchmaking, profilo. Italiano, tono amichevole/diretto/sportivo. Max 3 righe per messaggio, max 1 emoji.

## Stack
- Laravel 11 + Filament 3 (legacy admin)
- React SPA (Vite + Tailwind v4 + Shadcn) in `frontend/` → build `public/panel/`
- MySQL `lecercle_db`
- Gemini `gemini-2.5-flash` — SOLO date parsing + classificazione bottoni (no rephrase, template da DB)
- Google Calendar API (service account JSON)
- WhatsApp Business API (Meta Cloud v21.0)
- Server `bot.lecercleclub.it` su Plesk, TZ `Europe/Rome`

## Architettura — Principio
**L'AI NON controlla la logica.** Macchina a stati deterministica. Gemini solo per (1) parsing date NL fallback, (2) classificazione bottone se input non matcha.

```
WhatsAppController → BotOrchestrator → StateHandler → TextGenerator
                         ├ BotFlowState (DB, bottoni/transizioni editabili)
                         ├ CalendarService
                         ├ UserProfileService
                         └ WhatsAppService
```

## Mappa file
```
app/
├ Console/Commands/{SendMatchResultRequests,SendBookingReminders}.php
├ Filament/                          (legacy)
├ Http/Controllers/
│  ├ Api/{Auth,Dashboard,Booking,User,BotSession,MatchResult,
│  │      PricingRule,BotMessage,BotFlowState,Settings}Controller.php
│  └ WhatsAppController.php          (webhook, sempre 200)
├ Http/Middleware/EnsureIsAdmin.php
├ Models/{BotSession,Booking,MatchInvitation,MatchResult,EloHistory,
│         Feedback,BotSetting,BotMessage,BotFlowState,User}.php
└ Services/
   ├ {Calendar,Gemini,WhatsApp}Service.php
   └ Bot/
      ├ BotState.php           (enum + transitionTo validate)
      ├ BotPersona.php         (nomi tennisti + saluti)
      ├ BotResponse.php        (DTO + flag side-effect)
      ├ BotOrchestrator.php    (DB tx, side-effects, invio msg)
      ├ StateHandler.php       (macchina stati, tutta la logica)
      ├ TextGenerator.php      (template DB + parseDateTime + classify AI)
      ├ UserProfileService.php (saveFromBot + stima ELO)
      └ EloService.php
frontend/src/
├ components/{layout,auth,ui}/       (FormDialog, PlayerSearch, Shadcn)
├ pages/{login,dashboard,calendario,prenotazioni,giocatori,sessioni,
│        match,messaggi,flusso,impostazioni}.tsx
├ hooks/{use-api,use-auth}.ts
└ types/api.ts
```

## DB Schema

### `bot_sessions`
`id`, `phone` UNIQUE, `state` VARCHAR(30) DEFAULT 'NEW', `data` JSON, timestamps.

**`data` keys**: `persona`, `history` (max 40, `[{role,content}]`), `profile` ({name,is_fit,fit_rating,self_level,age,slot}), `booking_type` (con_avversario/matchmaking/sparapalline), `requested_{date,time,friendly,raw}`, `calendar_result` ({available,alternatives}), `alternatives`, `payment_method` (online/in_loco), `editing_booking_id`, `selected_booking_id`, `bookings_list`, `update_field` (fit/classifica/livello/slot), `pending_booking_id`, `opponent_{name,phone}`, `invited_by_{phone,name}`, `invited_{slot,booking_id}`.

### `users`
`id`, `name`, `email` (`wa_XXX@lecercleclub.bot`), `phone` UNIQUE, `password`, `is_fit`, `fit_rating` (NC/4.1/3.3..), `self_level` VARCHAR (neofita/dilettante/avanzato), `age`, `elo_rating` DEFAULT 1200, `matches_played`, `matches_won`, `is_elo_established`, `preferred_slots` JSON.

### `bookings`
`id`, `player1_id` FK, `player2_id` FK NULL, `booking_date`, `start_time`, `end_time`, `price`, `is_peak`, `status` (pending_match/confirmed/cancelled), `gcal_event_id`, `stripe_payment_link_p1/p2`, `payment_status_p1/p2`.

### `match_invitations`
`id`, `booking_id`, `receiver_id`, `status` (pending/accepted/refused).

### `bot_messages`
`key` PK, `text` (con `{vars}`), `category` (onboarding/menu/prenotazione/conferma/gestione/profilo/matchmaking/risultati/feedback/promemoria/errore), `description`, timestamps. Cache 1h. Fallback hardcoded in `TextGenerator::FALLBACKS`. Editabile in `/panel/messaggi`.

### `bot_flow_states`
`state` PK, `type` (`simple`=editabile / `complex`=logica custom), `message_key` FK, `fallback_key` FK NULL, `buttons` JSON `[{label,target_state,value?,side_effect?}]`, `category`, `description`, `sort_order`. Cache 1h. Editabile in `/panel/flusso`. `simple`: input non matchato → classificazione AI Gemini. `complex`: logica nel codice ma label/messaggi editabili.

### `bot_settings`
`key` PK, `value` JSON, timestamps. Es. `reminders`: `{enabled, slots:[{hours_before,enabled}]}`.

## Stati `BotState`

**Onboarding**: NEW, ONBOARD_NOME, ONBOARD_FIT, ONBOARD_CLASSIFICA, ONBOARD_LIVELLO, ONBOARD_ETA, ONBOARD_SLOT_PREF, ONBOARD_COMPLETO
**Menu**: MENU
**Prenotazione**: SCEGLI_QUANDO, SCEGLI_DURATA, VERIFICA_SLOT, PROPONI_SLOT, CONFERMA, PAGAMENTO, CONFERMATO
**Risultati/Feedback**: INSERISCI_RISULTATO, FEEDBACK, FEEDBACK_COMMENTO
**Matchmaking**: ATTESA_MATCH, RISPOSTA_MATCH
**Gestione**: GESTIONE_PRENOTAZIONI, AZIONE_PRENOTAZIONE
**Profilo**: MODIFICA_PROFILO, MODIFICA_RISPOSTA

### Transizioni (`allowedTransitions()`)
```
NEW                  → ONBOARD_NOME
ONBOARD_NOME         → ONBOARD_FIT
ONBOARD_FIT          → ONBOARD_CLASSIFICA | ONBOARD_LIVELLO | ONBOARD_NOME
ONBOARD_CLASSIFICA   → ONBOARD_ETA | ONBOARD_FIT
ONBOARD_LIVELLO      → ONBOARD_ETA | ONBOARD_FIT
ONBOARD_ETA          → ONBOARD_SLOT_PREF | ONBOARD_CLASSIFICA | ONBOARD_LIVELLO
ONBOARD_SLOT_PREF    → ONBOARD_COMPLETO | ONBOARD_ETA
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
GESTIONE_PRENOTAZIONI→ AZIONE_PRENOTAZIONE | MENU
AZIONE_PRENOTAZIONE  → SCEGLI_QUANDO | MENU
MODIFICA_PROFILO     → MODIFICA_RISPOSTA | MENU
MODIFICA_RISPOSTA    → MENU | MODIFICA_RISPOSTA
```
`transitionTo($t)` ritorna il target solo se dichiarato, altrimenti stato invariato.

## Flussi

### 1. Onboarding (nuovo utente)
NEW → ONBOARD_NOME (saluto + persona) → ONBOARD_FIT (`["Sì, sono tesserato","Non sono tesserato"]`) → ONBOARD_CLASSIFICA (se FIT) o ONBOARD_LIVELLO (`["Neofita","Dilettante","Avanzato"]`) → ONBOARD_ETA → ONBOARD_SLOT_PREF (`["Mattina","Pomeriggio","Sera"]`) → ONBOARD_COMPLETO (saveFromBot + menu `["Ho già un avversario","Trovami avversario","Sparapalline"]`).

Keyword `indietro`/sinonimi → step precedente (non in ONBOARD_NOME).

**Validazioni**: nome (lettere/spazi/apostrofi 2-60, Title Case auto); FIT (negativo prima del positivo); classifica (4.1-1.1, NC, "terza categoria"→3.1); livello (sinonimi: principiante→neofita, intermedio→dilettante, esperto→avanzato); età (primo num, 5-99); fascia (mattino→mattina, serale/tardi/dopo cena→sera). Input invalido → ripeti domanda.

### 2. Prenotazione (con_avversario / sparapalline)
Da MENU. SCEGLI_QUANDO → VERIFICA_SLOT (orchestrator: invia "verifico..." fuori tx, poi `checkUserRequest`, ri-processa) → PROPONI_SLOT (libero: `["Sì, prenota","No, cambia orario"]`; occupato: max 3 alternative come bottoni; nessuna: torna a SCEGLI_QUANDO) → CONFERMA (`["Paga online","Pago di persona","Annulla"]`) → PAGAMENTO o CONFERMATO (crea Booking + gcal event) → MENU.

**Modifica**: `editing_booking_id` → `createBooking()` cancella vecchio (gcal+DB) prima di crearne uno nuovo.

### 3. Matchmaking
Da MENU "Trovami avversario", `booking_type=matchmaking`. SCEGLI_QUANDO → VERIFICA_SLOT → PROPONI_SLOT → CONFERMA (`["Cerca avversario","Annulla"]`) → ATTESA_MATCH + flag `matchmakingToSearch`.

`triggerMatchmaking()`: cerca user con `elo_rating ±200`, ≠ challenger, con phone. Non trovato → "nessun avversario" + MENU. Trovato → crea Booking (`pending_match`), MatchInvitation (`pending`), aggiorna sessione avversario in `RISPOSTA_MATCH` con `invited_*`, invia WA invito + `["Accetta","Rifiuta"]`.

Challenger in ATTESA_MATCH (può `annulla`→MENU). Avversario in RISPOSTA_MATCH:
- Accetta → `confirmMatch()`: invitation=accepted, crea gcal event, booking=confirmed, notifica challenger, challenger→CONFERMATO
- Rifiuta → `refuseMatch()`: invitation=refused, booking=cancelled, notifica challenger, challenger→MENU

### 4. Gestione prenotazioni
Keyword `prenotazioni` da qualsiasi stato non-onboarding. `handleMostraPrenotazioni()`: prossime 3 (`confirmed`/`pending_match`, da oggi) come bottoni `Lun 6 apr 18:00`. GESTIONE_PRENOTAZIONI → match label/orario → AZIONE_PRENOTAZIONE (`["Modifica orario","Cancella","Torna al menu"]`):
- Modifica → salva `editing_booking_id`, → SCEGLI_QUANDO
- Cancella → `cancelBooking()` (gcal delete + status=cancelled)
- Menu → MENU

### 5. Modifica profilo
Keyword `profilo`. MODIFICA_PROFILO (`["Stato FIT","Livello gioco","Fascia oraria"]`) → MODIFICA_RISPOSTA (riusa parser onboarding) → `withProfileToSave()` → MENU.

### 6. Risultati partita
Scheduler `bot:send-result-requests` (15 min, 1h post partita) crea MatchResult e mette sessioni in INSERISCI_RISULTATO. Bottoni `["Ho vinto","Ho perso","Non giocata"]`. Keywords: vinto/perso/non giocata/annullata. Punteggio opzionale `\b(\d{1,2})[-\/](\d{1,2})\b`. Flag `withMatchResultToSave` → `processMatchResult()` aggiorna ruolo. Entrambi confermati → `finalizeMatchResult()` + `EloService::processResult()`. Discordanza → notifica entrambi, admin verifica. No_show → completata senza ELO. Poi → FEEDBACK.

### 7. Feedback
Keyword `feedback` o post-risultato. FEEDBACK (`["1","2","3","4","5"]`, parser: numeri/parole/emoji) → FEEDBACK_COMMENTO (no/skip/niente → senza commento). Flag `withFeedbackToSave` → `saveFeedback()` su tabella `feedbacks` (tipo, rating, contenuto, user/booking).

### 8. Promemoria
Scheduler `bot:send-reminders` (15 min). Legge `bot_settings.reminders`. Per ogni slot configurato: trova prenotazioni in finestra ±8 min, invia WA a player1+player2, cache 48h anti-duplicato. Template `reminder_giorno_prima` (≥12h) o `reminder_ore_prima` (<12h).

## Keyword globali (fuori onboarding)
Intercettate in `StateHandler::handle()` prima della FSM:
- `menu`/`home`/`aiuto`/`help`/`start`/`ricomincia`/`0`/`torna al menu` → MENU
- `prenotazioni`/`mie prenotaz`/`booking` → lista prenotazioni
- `feedback`/`valuta`/`vota`/`recensione`/`opinione` → FEEDBACK
- `profilo`/`modifica profilo`/`aggiorna profilo`/`impostazioni` → MODIFICA_PROFILO

In onboarding: `indietro`/`back`/`torna`/`annulla`/`precedente`/`torna indietro` → step precedente.

## Vincoli WhatsApp
- Max **3 pulsanti** per msg. Se 4+, testo libero.
- Max **20 char** per label (verrà troncato).
- `sendText(phone,msg)`, `sendButtons(phone,msg,buttons[])`.
- Controller risponde **sempre 200** a Meta.

## Template messaggi
Salvati in `bot_messages`, editabili in `/panel/messaggi`. `TextGenerator::rephrase()` legge da DB (cache 1h, no AI rephrase). Fallback hardcoded in `TextGenerator::FALLBACKS`.

```
Onboarding: nome_non_valido, chiedi_fit, fit_non_capito, chiedi_classifica,
  classifica_non_valida, chiedi_livello, livello_non_valido, chiedi_eta,
  eta_non_valida, chiedi_fascia_oraria, fascia_non_valida,
  registrazione_completa, indietro_onboarding, chiedi_nome_nuovo
Menu: menu_non_capito, menu_ritorno
Prenotazione: chiedi_quando, chiedi_quando_match, chiedi_quando_sparapalline,
  data_non_capita, verifico_disponibilita, slot_disponibile,
  slot_non_disponibile, nessuna_alternativa, proposta_non_capita
Conferma/Pagamento: riepilogo_prenotazione, scegli_pagamento,
  conferma_non_capita, prenotazione_annullata, link_pagamento,
  prenotazione_confermata
Matchmaking: cerca_avversario, matchmaking_attesa, nessun_avversario,
  invito_match, match_{accettato,rifiutato}_{challenger,opponent}
Gestione: nessuna_prenotazione, scegli_prenotazione, azione_prenotazione,
  prenotazione_cancellata_ok, prenotazione_modifica_quando
Profilo: modifica_profilo_scelta, profilo_aggiornato
Errori: errore_generico
```

Variabili: `{name}`, `{slot}`, `{opponent_name}`, `{challenger_name}`, etc.

## Google Calendar
- Credenziali: `storage/google-calendar-credentials.json`
- `GOOGLE_CALENDAR_ID` da `.env`
- Orari 08:00–22:00, slot 1h, TZ Europe/Rome

`checkUserRequest($query)`: parsa `YYYY-MM-DD HH:MM`, freebusy. Libero → `{available:true}`. Occupato → fino a 5 alternative del giorno con prezzo stimato.

`createEvent`: summary `"{Tipo} - {Nome}"` o `"Partita singolo - P1 vs P2"` per matchmaking. Description: giocatori, telefoni, tipo, pagamento, "Prenotato via WhatsApp Bot".

`deleteEvent($id)`: usato da `cancelBooking()` e modifica.

**Prezzi placeholder**: mattina 08-14 €20, pomeriggio 14-18 €25, sera 18-22 €30.

## Parser date locale
`TextGenerator::parseDateTimeLocal()`, priorità su Gemini.

| Input | Risultato |
|---|---|
| `domani alle 17` / `domani 15` / `domani alle 9:30` | domani + ora |
| `oggi pomeriggio` | oggi 15:00 |
| `sabato mattina` / `lunedì alle 18` | prossimo giorno + ora |
| `28 marzo` / `28/03` | data, no ora |
| `dopodomani ore 10` | dopodomani 10:00 |

Fasce: mattina=09, pranzo=13, pomeriggio=15, sera=19. Date passate → anno successivo. Solo se locale fallisce → Gemini (JSON strutturato).

## BotResponse — flag side-effect
Eseguiti dall'orchestrator:

| Flag | Azione |
|---|---|
| `withCalendarCheck` | Commit tx, invia "verifico...", riapri tx, calendar, ri-processa |
| `withBookingToCreate` | `createBooking()`: Booking DB + gcal event |
| `withBookingToCancel` | `cancelBooking()`: gcal delete + status=cancelled |
| `withProfileToSave($arr)` | `UserProfileService::saveFromBot()` |
| `withMatchmakingSearch` | `triggerMatchmaking()`: trova + crea + invito WA |
| `withMatchAccepted` | `confirmMatch()`: gcal event, booking confirmed, notifica |
| `withMatchRefused` | `refuseMatch()`: cancelled, notifica |
| `withMatchResultToSave` | `processMatchResult()` + ELO se entrambi confermati |
| `withFeedbackToSave` | `saveFeedback()` |
| `withPaymentRequired` | Flag (online non integrato) |

## Conversione classifica/livello → ELO
Default 1200. FIT prevale su autodichiarato.

| FIT | ELO | | FIT | ELO |
|---|---|---|---|---|
| NC | 1100 | | 3.4 | 1400 |
| 4.6 | 1050 | | 3.3 | 1450 |
| 4.5 | 1100 | | 3.2 | 1500 |
| 4.4 | 1150 | | 3.1 | 1550 |
| 4.3 | 1200 | | 2.8 | 1600 |
| 4.2 | 1250 | | 2.1 | 1950 |
| 4.1 | 1300 | | 1.1 | 2100 |
| 3.5 | 1350 | | | |

| Livello | ELO |
|---|---|
| Neofita | 1000 |
| Dilettante | 1200 |
| Avanzato | 1400 |

## Errori
- Chiamate esterne in `try/catch`
- Gemini fallisce → fallback fisso
- Calendar fallisce → msg errore generico
- WhatsApp fallisce → log, flusso continua
- Catastrofico → "Scusa, problema tecnico. Riprova! 🙏"
- DB tx copre l'operazione, rollback su fallimento
- Controller sempre 200 a Meta

## .env
```
WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_TOKEN, WHATSAPP_VERIFY_TOKEN=courtly_webhook_2026
GEMINI_KEY, GEMINI_MODEL=gemini-2.5-flash
GOOGLE_CALENDAR_CREDENTIALS, GOOGLE_CALENDAR_ID
APP_TIMEZONE=Europe/Rome
```
`.env` letto SOLO in `config/*.php`. Nel codice usare `config('services.x.y')`, mai `env()`.

## Filament Admin (legacy `/admin`)
Primary Amber. Auto-discovery `app/Filament/{Resources,Pages,Widgets}`. Auth: `is_admin=true`.

### Pagina `CalendarBookings` (`/admin/calendar-bookings`)
Pagina Livewire centro gestione prenotazioni.
- Vista giorno (08-22, 80px/h) + vista settimana (7 col, scroll mobile)
- Toggle Giorno/Settimana, navigazione prev/next/oggi, strip settimanale
- Stats: tot/confermate/in attesa/incasso (per contesto)
- Blocchi colorati: emerald=conf, amber=pending, sky=completata
- Linea rossa "adesso", auto-scroll
- Click slot vuoto → BookingResource/create con `?date=&time=` (snap 30min)
- Drag&drop: aggiorna DB+gcal+prezzo (PricingRule), ghost indicator, notifica
- Filtri: ricerca giocatore (debounce 300ms) + toggle stato
- Slide-over dettaglio (overlay/Esc/X)
- Badge nav con conteggio prenotazioni del giorno
- URL `selectedDate` via `#[Url]`

**Props**: `selectedDate` #[Url], `viewMode` (day/week), `filterPlayer`, `filterStatuses[]`, `selectedBooking?`.
**Computed**: `bookings`, `weekBookings`, `weekDays`, `formattedDate`, `stats`, `currentTimePosition`, `todayColumnIndex`.
**Metodi**: `moveBooking(id,date,time)`, `createAtSlot(date,time)`, `selectBooking(id)`, `closeDetail()`, `toggleStatus(s)`, `switchToDay(date)`.
**Alpine `calendarApp()`**: `scrollToNow`, `clickToCreate`, `dragStart/Over/Leave/End/drop`, ghost custom, blocchi non-dragged `opacity-40 pointer-events-none`.

File: `app/Filament/Pages/CalendarBookings.php`, `resources/views/filament/pages/calendar-bookings.blade.php`.

### Dashboard `/admin` widgets
| Widget | Tipo | Note |
|---|---|---|
| `StatsOverview` | StatsOverviewWidget | 4 card: prenotazioni oggi (sparkline+trend%), incasso, giocatori (+nuovi sett), match in attesa |
| `WeeklyBookingsChart` | ChartWidget bar stacked | 7gg per stato (emerald/amber/sky) |
| `TodaySchedule` | TableWidget | Oggi: orario, giocatori, badge, prezzo, peak |
| `LatestUsers` | TableWidget | Ultimi 8: nome, tel, FIT, livello, ELO, data |

Rimosso `FilamentInfoWidget`, mantenuto `AccountWidget`.

### Resources
| Resource | Modello | Tipo | Sort |
|---|---|---|---|
| UserResource | User | CRUD | 1 |
| BookingResource | Booking | CRUD (prefill `?date=&time=`) | 3 |
| BotSessionResource | BotSession | Read (Infolist) | 2 |
| MatchResultResource | MatchResult | List+Edit | 4 |
| FeedbackResource | Feedback | List+View | 5 |
| PricingRuleResource | PricingRule | CRUD reorderable | 6 |

## React SPA `/panel`
Vite + React 19 + TS, Tailwind v4 + Shadcn (base-ui), Recharts, Lucide. Build → `public/panel/` + `.htaccess` SPA. URL `https://bot.lecercleclub.it/panel/`.

**Auth**: session-based (stessa di Filament, no Sanctum). `POST /api/auth/login` (verifica `is_admin`), `GET /api/auth/me`. Middleware `auth+admin` su `/api/admin/*`. Frontend: `AuthProvider` + `RequireAuth`.

### API `/api/admin/...`
| Endpoint | Metodo | |
|---|---|---|
| `/dashboard/stats` | GET | stats + trend |
| `/dashboard/weekly-chart` | GET | 7gg stacked |
| `/bookings` | GET/POST | lista paginata / crea (auto-prezzo) |
| `/bookings/{id}` | GET/PUT/DELETE | |
| `/bookings/today` | GET | |
| `/bookings/calendar?from=&to=` | GET | grouped per giorno |
| `/users` | GET | filtri + sort |
| `/users/search?q=` | GET | autocomplete max 10 |
| `/users/{id}` | GET/PUT/DELETE | |
| `/bot-sessions` | GET | + chat history |
| `/match-results` | GET | |
| `/pricing-rules` | GET/POST | |
| `/pricing-rules/{id}` | PUT/DELETE | |
| `/settings` | GET | tutte |
| `/settings/{key}` | GET/PUT | |
| `/bot-messages` | GET | grouped per categoria |
| `/bot-messages/{key}` | PUT | |
| `/bot-flow-states` | GET | grouped + testi |
| `/bot-flow-states/{state}` | PUT | bottoni/transizioni |

## Scheduler
| Comando | Freq | |
|---|---|---|
| `bot:send-result-requests` | 15min | risultato 1h post partita |
| `bot:retry-matchmaking` | 5min | riprova chi è in attesa |
| `bot:send-reminders` | 15min | promemoria configurabili |

## Regole sviluppo
1. Logica stato SOLO in `StateHandler`. Nessun'altra classe decide transizioni.
2. AI SOLO in `TextGenerator` (date + classify bottoni). Messaggi da `bot_messages`, no rephrase. Classify è fallback se input non matcha bottone.
3. Side-effect (Calendar/DB/WA) → flag su `BotResponse`, eseguiti dall'orchestrator. Mai dall'handler.
4. Transizioni validate da `BotState::transitionTo()`. Non dichiarate → ignorate silenziosamente.
5. Ogni template ha fallback hardcoded in `TextGenerator::FALLBACKS`. Bot funziona anche senza DB/Gemini.
6. Parser date locale prima, Gemini fallback per input complessi.
7. Max 3 pulsanti, max 20 char label.
8. Template corti (<100 char idealmente).
9. DB tx copre l'intera operazione di sessione per ogni messaggio.
10. Log con contesto: phone, input, stato, errore.
11. Lingua sempre italiano (msg, template, log).
12. Dopo ogni implementazione, aggiornare questo file.
