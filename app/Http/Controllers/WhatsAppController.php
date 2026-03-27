<?php

namespace App\Http\Controllers;

use App\Models\BotSession;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\WhatsAppService;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    private WhatsAppService $wa;
    private GeminiService $gemini;

    private CalendarService $calendar;

    public function __construct(WhatsAppService $wa, GeminiService $gemini, CalendarService $calendar)
    {
        $this->wa       = $wa;
        $this->gemini   = $gemini;
        $this->calendar = $calendar;
    }
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === env('WHATSAPP_VERIFY_TOKEN')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request): Response
    {
        $data    = $request->all();
        $message = data_get($data, 'entry.0.changes.0.value.messages.0');

        if (!$message) {
            return response('OK', 200);
        }

        $from  = $message['from'];
        $input = $this->parseInput($message);

        if (empty($input)) {
            return response('OK', 200);
        }

        // Verifica se l'utente è già registrato nel DB
        $existingUser = \App\Models\User::where('phone', $from)->first();
        $isRegistered = $existingUser !== null;

        // Recupera o crea la sessione
        $session = BotSession::firstOrCreate(
            ['phone' => $from],
            ['state' => $isRegistered ? 'MENU' : 'NEW', 'data' => []]
        );

        // Costruisci la history della conversazione
        $history = $session->data['history'] ?? [];

        // Verifica disponibilità Calendar solo quando l'utente ha indicato giorno/ora
        $calendarInfo = null;
        if ($session->state === 'VERIFICA_SLOT') {
            try {
                $calendarInfo = $this->calendar->checkUserRequest($input);
            } catch (\Exception $e) {
                \Log::error('Calendar error', ['message' => $e->getMessage()]);
            }
        }

        // Costruisci il system prompt
        $systemPrompt = $this->buildSystemPrompt($session, $isRegistered, $existingUser, $calendarInfo);

        // Chiama Gemini
        $reply = $this->gemini->chat($systemPrompt, $history, $input);

        // Estrai JSON dalla risposta
        $result = $this->parseGeminiResponse($reply);

        // Aggiorna la history
        $history[] = ['role' => 'user',  'content' => $input];
        $history[] = ['role' => 'model', 'content' => $result['message']];

        if (count($history) > 40) {
            $history = array_slice($history, -40);
        }

        // Aggiorna sessione
        $newData = array_merge($session->data ?? [], [
            'history'    => $history,
            'state'      => $result['next_state'] ?? $session->state,
            'last_input' => $input,
        ]);

        if (!empty($result['profile'])) {
            $newData['profile'] = array_merge($newData['profile'] ?? [], $result['profile']);
            $this->saveUserProfile($from, $newData['profile']);
        }

        $session->update([
            'state' => $result['next_state'] ?? $session->state,
            'data'  => $newData,
        ]);

        // Invia risposta
        if (!empty($result['buttons']) && count($result['buttons']) <= 3) {
            $this->wa->sendButtons($from, $result['message'], $result['buttons']);
        } else {
            $this->wa->sendText($from, $result['message']);
        }

        return response('OK', 200);
    }

    private function buildSystemPrompt(BotSession $session, bool $isRegistered, ?\App\Models\User $user = null, ?array $calendarInfo = null): string
    {
        $profile = $session->data['profile'] ?? [];
        $state   = $session->state;

        $userName = $user?->name ?? 'ospite';

        $calendarText = '';
        if ($calendarInfo) {
            if ($calendarInfo['available']) {
                $calendarText = "DISPONIBILITA': Lo slot richiesto e' LIBERO.";
            } else {
                $alternatives = collect($calendarInfo['alternatives'] ?? [])
                    ->map(fn($s) => "- {$s['label']} (€{$s['price']})")
                    ->implode("\n");
                $calendarText = "DISPONIBILITA': Lo slot richiesto NON e' disponibile.\nAlternative nello stesso giorno:\n{$alternatives}";
            }
        }

        if (!$isRegistered) {
            return <<<PROMPT
Sei il bot di Le Cercle Tennis Club, un circolo tennistico a San Gennaro Vesuviano (NA).
Parli sempre in italiano, con tono amichevole e diretto.

STATO CORRENTE: {$state}
PROFILO RACCOLTO FINORA: {$this->formatProfile($profile)}

L'utente NON e' ancora registrato al circolo. Devi raccogliere le sue informazioni.

FLUSSO ONBOARDING:
1. NEW: Dai il benvenuto e spiega che per prenotare serve registrarsi. Chiedi il nome.
2. ONBOARD_NOME: Salva il nome. Chiedi se e' tesserato FIT. Pulsanti: ["Si', sono tesserato", "No, non sono tesserato"]
3a. Se tesserato FIT: chiedi la classifica (es. 4.1, 3.3, NC).
3b. Se non tesserato: chiedi il livello. Pulsanti: ["Neofita", "Dilettante", "Avanzato"]
4. ONBOARD_ETA: Chiedi l'eta'.
5. ONBOARD_SLOT_PREF: Chiedi fascia oraria preferita. Pulsanti: ["Mattina", "Pomeriggio", "Sera"]
6. ONBOARD_COMPLETO: Conferma registrazione e mostra le tre opzioni. Pulsanti: ["Ho gia' un avversario", "Trovami un avversario", "Noleggio sparapalline"]

REGOLE:
- Fai UNA sola domanda alla volta.
- Sii breve — massimo 3 righe.
- Se l'utente corregge un dato, aggiornalo nel profile e conferma.

RISPOSTA in JSON:
{
  "message": "testo",
  "next_state": "NEW|ONBOARD_NOME|ONBOARD_FIT|ONBOARD_LIVELLO|ONBOARD_ETA|ONBOARD_SLOT_PREF|ONBOARD_COMPLETO|MENU",
  "buttons": [],
  "profile": {}
}
PROMPT;
        }

        return <<<PROMPT
Sei il bot di Le Cercle Tennis Club, un circolo tennistico a San Gennaro Vesuviano (NA).
Parli sempre in italiano, con tono amichevole e diretto.

STATO CORRENTE: {$state}
UTENTE: {$userName} (gia' registrato)

{$calendarText}

FLUSSO PRENOTAZIONE:
1. MENU: Saluta {$userName} per nome e chiedi cosa vuole fare. Pulsanti: ["Ho gia' un avversario", "Trovami un avversario", "Noleggio sparapalline"]

2. SCEGLI_QUANDO: Chiedi quando vuole giocare in modo naturale. Es: "Quando vorresti venire? Dimmi giorno e ora preferita (es. domani alle 18, sabato mattina...)"
   NON mostrare slot. Aspetta che l'utente dica quando vuole.

3. VERIFICA_SLOT: Hai ricevuto il giorno/ora dall'utente. Il sistema sta verificando la disponibilita'.
   {$calendarText}
   - Se LIBERO: "Perfetto! Sabato 28 marzo alle 18:00 e' disponibile — confermo la prenotazione?" Pulsanti: ["Si', prenota", "No, scegli altro"]
   - Se NON disponibile con alternative: "Quel momento non e' libero, ma ho disponibile: [lista alternative]. Quale preferisci?"
   - Se NON disponibile senza alternative: "Mi dispiace, quel giorno non ho altri slot liberi. Vuoi provare un altro giorno?"

4. CONFERMA: Riepilogo prenotazione e chiedi modalita' pagamento. Pulsanti: ["Paga online", "Pago di persona"]

5. Se "Trovami un avversario": prima del flusso prenotazione, raccogli profilo tennistico (FIT/livello) se non presente.

REGOLE:
- Fai UNA sola domanda alla volta.
- Sii breve — massimo 3 righe.
- NON inventare disponibilita' — usa solo i dati in DISPONIBILITA' qui sopra.
- Se l'utente corregge qualcosa, aggiorna e conferma.

RISPOSTA in JSON:
{
  "message": "testo",
  "next_state": "MENU|SCEGLI_QUANDO|VERIFICA_SLOT|CONFERMA|PAGAMENTO|ATTESA_MATCH|CONFERMATO",
  "buttons": [],
  "profile": {},
  "requested_slot": null
}

Il campo "requested_slot" va popolato con il testo dell'orario richiesto dall'utente (es. "sabato 28 marzo alle 18:00") quando lo stato e' VERIFICA_SLOT.
PROMPT;
    }

    private function formatProfile(array $profile): string
    {
        if (empty($profile)) return "Nessun dato ancora raccolto.";

        $lines = [];
        if (isset($profile['name']))       $lines[] = "Nome: " . $profile['name'];
        if (isset($profile['is_fit']))     $lines[] = "Tesserato FIT: " . ($profile['is_fit'] ? 'sì' : 'no');
        if (isset($profile['fit_rating'])) $lines[] = "Classifica: " . $profile['fit_rating'];
        if (isset($profile['self_level'])) $lines[] = "Livello: " . $profile['self_level'];
        if (isset($profile['age']))        $lines[] = "Età: " . $profile['age'];
        if (isset($profile['slot']))       $lines[] = "Fascia oraria: " . $profile['slot'];

        return implode(', ', $lines);
    }

    private function parseGeminiResponse(string $reply): array
    {
        // Rimuovi markdown code blocks se presenti
        $clean = preg_replace('/```json\s*|\s*```/', '', $reply);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['message'])) {
            Log::warning('Gemini non ha risposto in JSON', ['reply' => $reply]);
            return [
                'message'    => $reply,
                'next_state' => null,
                'buttons'    => [],
                'profile'    => [],
            ];
        }

        return $decoded;
    }

    private function saveUserProfile(string $phone, array $profile): void
    {
        try {
            User::updateOrCreate(
                ['phone' => $phone],
                [
                    'name'            => ($profile['name'] ?? 'Giocatore ') . substr($phone, -4),
                    'email'           => $phone . '@lecercleclub.it',
                    'password'        => bcrypt(\Str::random(16)),
                    'is_fit'          => $profile['is_fit'] ?? false,
                    'fit_rating'      => $profile['fit_rating'] ?? null,
                    'self_level'      => $profile['self_level'] ?? null,
                    'elo_rating'      => 1200,
                    'preferred_slots' => isset($profile['slot']) ? [$profile['slot']] : [],
                ]
            );
        } catch (\Exception $e) {
            Log::error('Errore salvataggio profilo', ['error' => $e->getMessage()]);
        }
    }

    private function parseInput(array $message): string
    {
        return match($message['type']) {
            'interactive' => data_get($message, 'interactive.button_reply.id')
                ?? data_get($message, 'interactive.list_reply.id')
                ?? '',
            'text'        => data_get($message, 'text.body', ''),
            default       => '',
        };
    }
}
