# Le Cercle Tennis Club — Bot WhatsApp

## Identità

Bot WhatsApp per **Le Cercle Tennis Club**, circolo tennistico a **San Gennaro Vesuviano (NA)**. Il bot gestisce registrazione utenti, prenotazione campi e (futuro) matchmaking tra giocatori. Comunicazione in **italiano**, tono **amichevole, diretto, sportivo**. Massimo 3 righe per messaggio, max 1 emoji.

## Persona del Bot

Ad ogni nuova sessione il bot si presenta con il nome di un tennista famoso scelto a caso: Jannik, Carlos, Daniil, Rafa, Roger, Novak, Matteo, Lorenzo, Flavia, Francesca, Sara, Jasmine, Fabio, Adriano, Simone, Roberta, Andre, Serena, Stefanos, Alexander. Il nome viene salvato in sessione e usato per tutta la conversazione. Esempio di saluto: *"Ciao! Sono Jannik, il tuo assistente virtuale per il circolo Le Cercle Tennis Club! 🎾"*

---

## Stack Tecnologico

- **Framework**: Laravel 11 con Filament Admin Panel
- **Database**: MySQL (`lecercle_db`)
- **AI per testi**: Google Gemini API (modello `gemini-2.5-flash`) — usata SOLO per riformulare messaggi e parsare date complesse
- **Calendario**: Google Calendar API (service account con credenziali JSON)
- **Messaggistica**: WhatsApp Business API (Meta Cloud API)
- **Server**: `bot.lecercleclub.it` su Plesk
- **Timezone**: `Europe/Rome`

---

## Architettura — Principio Fondamentale

**L'AI NON controlla la logica.** La macchina a stati è deterministica. Gemini viene invocata solo per:
1. **Riformulare i messaggi template** — per variare il tono ad ogni interazione (con fallback al testo fisso se Gemini fallisce)
2. **Parsare date in linguaggio naturale** — solo come fallback quando il parser locale deterministico non riesce

Il flusso è:

```
WhatsAppController          → Sottile: valida webhook, estrae input, delega
    │
    └─▶ BotOrchestrator     → Coordina: sessione, side-effects, invio messaggi
            │
            ├─▶ StateHandler        → Macchina a stati DETERMINISTICA
            │       │
            │       └─▶ TextGenerator   → UNICO punto AI (Gemini)
            │                              Solo per: riformulare testi + parsare date
            │
            ├─▶ CalendarService     → Google Calendar: verifica disponibilità + crea eventi
            ├─▶ UserProfileService  → Persistenza utente su DB
            └─▶ WhatsAppService     → Invio messaggi WhatsApp (testo + pulsanti)
```

---

## File del Progetto

```
app/
├── Http/Controllers/
│   └── WhatsAppController.php          ← Controller sottile (webhook verify + handle)
├── Models/
│   ├── BotSession.php                  ← Sessione bot (phone, state, data JSON)
│   └── User.php                        ← Modello utente
└── Services/
    ├── CalendarService.php             ← Google Calendar API (check + create)
    ├── GeminiService.php               ← Client Gemini (generate + chat)
    ├── WhatsAppService.php             ← Client WhatsApp (sendText + sendButtons)
    └── Bot/
        ├── BotState.php                ← Enum con transizioni validate
        ├── BotPersona.php              ← Nomi tennisti + saluti
        ├── BotResponse.php             ← DTO risposta (messaggio + stato + side-effects)
        ├── BotOrchestrator.php         ← Coordinatore principale
        ├── StateHandler.php            ← Macchina a stati deterministica (tutta la logica)
        ├── TextGenerator.php           ← Unico punto AI: rephrase + date parsing
        └── UserProfileService.php      ← Salva profilo utente nel DB
```

---

## Tabella `bot_sessions`

```sql
CREATE TABLE bot_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    state VARCHAR(30) NOT NULL DEFAULT 'NEW',
    data JSON,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

Il campo `data` (JSON) contiene:
- `persona`: nome del tennista per questa sessione (es. "Jannik")
- `history`: array di `{role: "user"|"model", content: "..."}` — max 40 messaggi
- `profile`: dati raccolti durante onboarding (`name`, `is_fit`, `fit_rating`, `self_level`, `age`, `slot`)
- Dati temporanei della prenotazione: `booking_type`, `requested_date`, `requested_time`, `requested_friendly`, `requested_raw`, `calendar_result`, `alternatives`, `payment_method`

---

## Tabella `users`

```sql
users:
  - id (PK, auto-increment)
  - name (varchar)
  - email (varchar, unique) — placeholder per utenti WhatsApp: wa_XXXXXXXXXX@lecercleclub.bot
  - phone (varchar, unique) — numero WhatsApp
  - password (varchar, hashed) — password random, l'utente accede via WhatsApp
  - is_fit (boolean) — tesserato FIT?
  - fit_rating (varchar, nullable) — classifica FIT: NC, 4.1, 4.2, ..., 1.1
  - self_level (varchar, nullable) — livello autodichiarato: neofita, dilettante, avanzato
  - age (integer, nullable)
  - elo_rating (integer, default 1200)
  - preferred_slots (JSON) — ["mattina"], ["pomeriggio"], ["sera"]
```

---

## Macchina a Stati — Enum `BotState`

### Stati e Transizioni

```
NEW ──────────────────▶ ONBOARD_NOME
ONBOARD_NOME ─────────▶ ONBOARD_FIT
ONBOARD_FIT ──────────▶ ONBOARD_CLASSIFICA (se tesserato FIT)
                       ▶ ONBOARD_LIVELLO    (se non tesserato)
ONBOARD_CLASSIFICA ───▶ ONBOARD_ETA
ONBOARD_LIVELLO ──────▶ ONBOARD_ETA
ONBOARD_ETA ──────────▶ ONBOARD_SLOT_PREF
ONBOARD_SLOT_PREF ────▶ ONBOARD_COMPLETO
ONBOARD_COMPLETO ─────▶ MENU

MENU ─────────────────▶ SCEGLI_QUANDO      (prenotazione)
                       ▶ ATTESA_MATCH        (matchmaking)
SCEGLI_QUANDO ────────▶ VERIFICA_SLOT       (data/ora parsata)
                       ▶ MENU                (annulla)
VERIFICA_SLOT ────────▶ PROPONI_SLOT
PROPONI_SLOT ─────────▶ CONFERMA            (utente accetta)
                       ▶ SCEGLI_QUANDO       (utente cambia orario)
                       ▶ MENU                (annulla)
CONFERMA ─────────────▶ PAGAMENTO           (paga online)
                       ▶ CONFERMATO          (paga di persona → crea evento)
                       ▶ SCEGLI_QUANDO       (cambia)
                       ▶ MENU                (annulla)
PAGAMENTO ────────────▶ CONFERMATO          (crea evento)
                       ▶ MENU
CONFERMATO ───────────▶ MENU               (qualunque messaggio)
ATTESA_MATCH ─────────▶ SCEGLI_QUANDO
                       ▶ MENU
```

**Regola:** Le transizioni sono dichiarate nell'enum `BotState::allowedTransitions()`. Se si tenta una transizione non valida, lo stato rimane invariato. Questo impedisce salti accidentali.

---

## Flussi Conversazionali

### Flusso 1: Onboarding (utente NON registrato)

L'utente scrive per la prima volta. Il bot:

1. **NEW → ONBOARD_NOME**: Saluta con il nome del tennista, spiega che serve registrarsi, chiede il nome
2. **ONBOARD_NOME → ONBOARD_FIT**: Salva il nome, chiede se è tesserato FIT. Pulsanti: `["Sì, sono tesserato", "No, non sono tesserato"]`
3a. **ONBOARD_FIT → ONBOARD_CLASSIFICA** (se FIT): Chiede la classifica (es. 4.1, 3.3, NC)
3b. **ONBOARD_FIT → ONBOARD_LIVELLO** (se non FIT): Chiede il livello. Pulsanti: `["Neofita", "Dilettante", "Avanzato"]`
4. **→ ONBOARD_ETA**: Chiede l'età
5. **→ ONBOARD_SLOT_PREF**: Chiede fascia oraria preferita. Pulsanti: `["Mattina", "Pomeriggio", "Sera"]`
6. **→ ONBOARD_COMPLETO**: Conferma registrazione, salva nel DB, mostra menu. Pulsanti: `["Ho già un avversario", "Trovami un avversario", "Noleggio sparapalline"]`

**Validazione input durante onboarding:**
- **Nome**: solo lettere, spazi, apostrofi. Min 2, max 60 caratteri. Title Case automatico.
- **Tesserato FIT**: pattern matching per sì/no con varianti italiane
- **Classifica FIT**: accetta "4.1", "NC", "n.c.", "terza categoria" ecc.
- **Livello**: mappa sinonimi (principiante→neofita, intermedio→dilettante, esperto→avanzato)
- **Età**: estrae il primo numero, valida range 5-99
- **Fascia oraria**: mappa sinonimi (mattino→mattina, serale→sera, tardi→sera, dopo cena→sera)

Se l'input non è valido, il bot ripete la domanda nello stesso stato senza avanzare.

### Flusso 2: Prenotazione (utente registrato)

L'utente è registrato (esiste nella tabella `users` con quel numero di telefono). Il bot:

1. **MENU**: Saluta per nome con il persona del tennista. Pulsanti: `["Ho già un avversario", "Trovami un avversario", "Noleggio sparapalline"]`
2. **SCEGLI_QUANDO**: Chiede giorno e ora in linguaggio naturale
3. **VERIFICA_SLOT**: Il sistema verifica la disponibilità su Google Calendar
   - Se **libero** → **PROPONI_SLOT**: "Ottima notizia! [slot] è libero. Confermo?" Pulsanti: `["Sì, prenota", "No, cambia orario"]`
   - Se **occupato con alternative** → **PROPONI_SLOT**: Mostra fino a 3 alternative come pulsanti
   - Se **occupato senza alternative** → **SCEGLI_QUANDO**: "Nessuno slot libero quel giorno. Prova un altro giorno?"
4. **CONFERMA**: Riepilogo prenotazione. Pulsanti: `["Conferma e paga online", "Pago di persona", "Annulla"]`
5. **CONFERMATO / PAGAMENTO**: Crea l'evento su Google Calendar e conferma

### Flusso 3: Matchmaking (placeholder per fase futura)

L'utente sceglie "Trovami un avversario". Per ora:
- Viene indirizzato a **SCEGLI_QUANDO** (prenotazione normale)
- In futuro: sistema ELO con filtraggio per rating (±200 punti), età, e fascia oraria

---

## Parsing Date — Parser Locale

Il parser locale deterministico in `TextGenerator::parseDateTimeLocal()` gestisce SENZA chiamare l'AI:

| Input utente | Data risultante | Ora risultante |
|---|---|---|
| `domani alle 17` | tomorrow | 17:00 |
| `domani 15` | tomorrow | 15:00 |
| `domani alle 9:30` | tomorrow | 09:30 |
| `oggi pomeriggio` | today | 15:00 |
| `sabato mattina` | next saturday | 09:00 |
| `lunedì alle 18` | next monday | 18:00 |
| `28 marzo` | 28 march (current/next year) | null |
| `28/03` | 28 march | null |
| `dopodomani ore 10` | day after tomorrow | 10:00 |

**Fasce orarie generiche**: mattina=09:00, pranzo=13:00, pomeriggio=15:00, sera=19:00.

Solo se il parser locale fallisce, si chiama Gemini come fallback.

---

## Google Calendar

### Configurazione
- **Credenziali**: service account JSON in `storage/google-calendar-credentials.json`
- **Calendar ID**: nell'`.env` come `GOOGLE_CALENDAR_ID`
- **Orari operativi**: 08:00 – 22:00
- **Durata slot default**: 1 ora
- **Timezone**: `Europe/Rome`

### Verifica Disponibilità (`checkUserRequest`)
1. Riceve la stringa data/ora (formato `YYYY-MM-DD HH:MM`)
2. Controlla se ci sono eventi sovrapposti in quel range
3. Se libero: restituisce `{available: true}`
4. Se occupato: cerca tutti gli slot liberi di 1 ora nella giornata (08:00–22:00), filtra quelli nel passato, restituisce max 5 alternative con prezzo stimato

### Creazione Evento (`createEvent`)
L'evento viene creato alla conferma della prenotazione con:
- **Summary**: `"{Tipo prenotazione} - {Nome utente}"` (es. "Partita singolo - Marco")
- **Description**: Giocatore, telefono, tipo, pagamento, "Prenotato via: WhatsApp Bot"
- **Start/End**: datetime con timezone Europe/Rome

### Fasce Prezzo (placeholder)
- Mattina (08-14): €20
- Pomeriggio (14-18): €25
- Sera (18-22): €30

---

## Messaggi WhatsApp

### Vincoli WhatsApp Business API
- **Pulsanti**: massimo 3 per messaggio. Se servono più opzioni, usare testo libero.
- **Testo**: nessun limite pratico ma teniamo max 3 righe per UX
- Due metodi di invio: `sendText(phone, message)` e `sendButtons(phone, message, buttons[])`

### Template Messaggi con Fallback

Ogni messaggio ha un template fisso che funziona anche senza AI. Gemini lo riformula per variare il tono. Se Gemini fallisce, viene inviato il template originale.

Templates principali:
- `chiedi_fit`: "Piacere {name}! Sei tesserato FIT?"
- `chiedi_classifica`: "Ottimo! Qual è la tua classifica FIT? (es. 4.1, 3.3, NC)"
- `chiedi_livello`: "Nessun problema! Come definiresti il tuo livello?"
- `chiedi_eta`: "Quanti anni hai?"
- `chiedi_fascia_oraria`: "Ultima cosa: quando preferisci giocare di solito?"
- `registrazione_completa`: "Perfetto {name}, sei registrato! 🎉 Cosa vuoi fare?"
- `chiedi_quando`: "Quando vorresti giocare? Dimmi giorno e ora (es. domani alle 18, sabato mattina...)."
- `verifico_disponibilita`: "Un attimo, verifico la disponibilità... ⏳"
- `slot_disponibile`: "Ottima notizia! {slot} è libero. Confermo la prenotazione?"
- `slot_non_disponibile`: "Purtroppo quell'orario non è disponibile. Ho trovato queste alternative:"
- `riepilogo_prenotazione`: "Riepilogo: prenotazione per {slot}. Come vuoi procedere?"
- `prenotazione_confermata`: "Prenotazione confermata per {slot}! ✅ Ti aspettiamo!"
- `errore_generico`: "Scusa, c'è stato un problema. Riproviamo: quando vorresti giocare?"

---

## Profilo Utente — Conversione Classifica → ELO

Quando l'utente si registra, il sistema stima un ELO iniziale:

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

---

## Error Handling

- **Ogni chiamata esterna** (Gemini, Calendar, WhatsApp) è wrappata in try/catch
- **Se Gemini fallisce**: il template di fallback viene usato al posto del testo riformulato — il bot funziona comunque
- **Se il Calendar fallisce**: l'utente riceve un messaggio di errore generico
- **Se WhatsApp send fallisce**: viene loggato come errore, il flusso continua
- **Errore catastrofico**: l'utente riceve "Scusa, ho avuto un problema tecnico. Riprova tra qualche istante! 🙏"
- **Il controller risponde SEMPRE 200 a Meta** — anche in caso di errore interno — per evitare retry infiniti del webhook

---

## Variabili d'Ambiente Rilevanti

```env
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_TOKEN=...
WHATSAPP_VERIFY_TOKEN=courtly_webhook_2026
GEMINI_KEY=...
GEMINI_MODEL=gemini-2.5-flash
GOOGLE_CALENDAR_CREDENTIALS=/path/to/google-calendar-credentials.json
GOOGLE_CALENDAR_ID=...@group.calendar.google.com
```

Le variabili `.env` vengono lette SOLO nei file `config/*.php`, MAI direttamente nel codice con `env()`. Il codice usa `config('services.whatsapp.api_token')`, `config('services.gemini.api_key')`, ecc.

---

## Regole per lo Sviluppo

1. **La logica di stato va SOLO in `StateHandler`**. Mai nelle altre classi.
2. **L'AI va SOLO in `TextGenerator`**. Mai negli handler o nell'orchestrator.
3. **I side-effect** (Calendar, DB, WhatsApp) vengono segnalati tramite flag nel `BotResponse` ed eseguiti dall'orchestrator.
4. **Le transizioni di stato** sono validate dall'enum `BotState`. Transizioni non dichiarate vengono ignorate silenziosamente (lo stato rimane invariato).
5. **Ogni messaggio ha un template di fallback**. Il bot deve funzionare anche con Gemini completamente offline.
6. **Il parser di date locale ha la priorità**. Gemini è solo fallback per input che il parser locale non gestisce.
7. **Max 3 pulsanti** per messaggio WhatsApp (vincolo API Meta).
8. **La transazione DB** copre tutta l'operazione di sessione. Se qualcosa fallisce, rollback e messaggio di errore all'utente.
9. **I log** devono includere sempre il contesto: phone, input, stato corrente, errore.
10. **La lingua è sempre l'italiano**. Tutti i messaggi, i template, il tono.
