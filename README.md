# Refactoring WhatsApp Bot — Le Cercle Tennis Club

## Architettura

```
WhatsAppController          ← Sottile: valida webhook, estrae input, delega
    │
    └─▶ BotOrchestrator     ← Coordina: sessione, side-effects, invio messaggi
            │
            ├─▶ StateHandler        ← Macchina a stati DETERMINISTICA
            │       │
            │       └─▶ TextGenerator   ← UNICO punto AI (Gemini)
            │                              Solo per: riformulare testi + parsare date
            │
            ├─▶ CalendarService     ← Verifica disponibilità
            ├─▶ UserProfileService  ← Persistenza utente
            └─▶ WhatsAppService     ← Invio messaggi
```

## Cosa è cambiato e perché

### 1. L'AI NON decide più le transizioni di stato

**Prima:** Gemini riceveva un mega-prompt con tutte le regole e restituiva un JSON con
`next_state`, `buttons`, `profile`. Se il JSON era malformato o lo stato inventato,
il bot si rompeva.

**Dopo:** La macchina a stati è nell'enum `BotState` con transizioni esplicite.
`StateHandler` gestisce ogni stato con un metodo dedicato. L'AI fa solo due cose:
- Riformula i messaggi per variare il tono (con fallback al template fisso)
- Interpreta date in linguaggio naturale ("domani alle 5", "sabato pomeriggio")

### 2. Enum `BotState` con transizioni validate

Ogni stato dichiara le sue transizioni lecite. Se l'orchestrator tenta una
transizione non valida, rimane nello stato corrente. Zero sorprese.

### 3. `BotResponse` come DTO immutabile

Ogni handler restituisce un `BotResponse` con:
- Il testo del messaggio
- Il nuovo stato
- I pulsanti opzionali (max 3 per i limiti di WhatsApp)
- Flag per side-effects: `needsCalendarCheck()`, `needsBookingCreation()`, etc.

L'orchestrator legge i flag e agisce di conseguenza.

### 4. Persona del tennista famoso

Ad ogni nuova sessione, `BotPersona::pickRandom()` sceglie un nome casuale
(Jannik, Carlos, Daniil, Rafa, Roger, Novak, Matteo, ecc.) e lo salva in sessione.
Tutti i messaggi vengono riformulati dall'AI come se parlasse quel tennista.

### 5. Controller sottile

Il controller fa solo tre cose:
1. Verifica il webhook Meta
2. Estrae il messaggio dal payload
3. Chiama `$orchestrator->process()`

Rispondi SEMPRE 200 a Meta, anche in caso di errore interno (evita retry infiniti).

### 6. Niente più `env()` nel codice

L'originale usava `env('WHATSAPP_VERIFY_TOKEN')` nel controller.
Ora tutto passa da `config('services.whatsapp.verify_token')`.
Vedi `config/services_additions.php` per le chiavi da aggiungere.

### 7. Parsing input robusto

- **Nome:** sanitizzato con regex (solo lettere/spazi/apostrofi), min 2 max 60 chars
- **Classifica FIT:** accetta formati come "4.1", "NC", "terza categoria"
- **Età:** estrae il primo numero, valida range 5-99
- **Fascia oraria:** normalizza sinonimi (mattino→mattina, serale→sera, ecc.)
- **Sì/No:** pattern matching multilingua con varianti italiane

### 8. ELO iniziale stimato

`UserProfileService` mappa classifica FIT → ELO iniziale con una tabella realistica
(NC→1100, 4.1→1300, 3.1→1550, ecc.) anziché dare 1200 a tutti.

### 9. Error handling pervasivo

- Ogni metodo che chiama servizi esterni è wrappato in try/catch
- Errori loggati con contesto (phone, input, stato)
- L'utente riceve sempre un messaggio, anche in caso di errore catastrofico
- La transazione DB copre tutte le operazioni di sessione

## File prodotti

```
app/
├── Http/Controllers/
│   └── WhatsAppController.php          ← Controller sottile
├── Models/
│   └── BotSession.php                  ← Model con helper per dati/history
└── Services/
    ├── GeminiService.php               ← Client Gemini con generate() e chat()
    └── Bot/
        ├── BotState.php                ← Enum con transizioni validate
        ├── BotPersona.php              ← Nomi tennisti + saluti
        ├── BotResponse.php             ← DTO risposta
        ├── BotOrchestrator.php         ← Coordinatore principale
        ├── StateHandler.php            ← Macchina a stati deterministica
        ├── TextGenerator.php           ← Unico punto AI (rephrase + date parsing)
        └── UserProfileService.php      ← Persistenza profilo utente

config/
└── services_additions.php              ← Chiavi config da aggiungere
```

## Come integrare

1. Copia i file nelle rispettive cartelle del progetto Laravel
2. Aggiungi le chiavi di `config/services_additions.php` al tuo `config/services.php`
3. Registra il binding nell'AppServiceProvider se necessario (Laravel risolve automaticamente le dipendenze via constructor injection)
4. Aggiorna le route per puntare al nuovo controller (l'API è identica)
