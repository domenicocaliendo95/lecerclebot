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

        // Recupera o crea la sessione
        $session = BotSession::firstOrCreate(
            ['phone' => $from],
            ['state' => 'NEW', 'data' => []]
        );

        // Costruisci la history della conversazione
        $history = $session->data['history'] ?? [];

        $slots = [];
        if (in_array($session->state, ['SCEGLI_DATA', 'SCEGLI_SLOT', 'NEW', 'PROFILO'])) {
            try {
                $slots = $this->calendar->getFreeSlots(5);
            } catch (\Exception $e) {
                \Log::error('Calendar error', ['message' => $e->getMessage()]);
            }
        }

        $systemPrompt = $this->buildSystemPrompt($session, $slots);

        // Chiama Gemini
        $reply = $this->gemini->chat($systemPrompt, $history, $input);

        // Estrai JSON dalla risposta
        $result = $this->parseGeminiResponse($reply);

        // Aggiorna la history
        $history[] = ['role' => 'user',  'content' => $input];
        $history[] = ['role' => 'model', 'content' => $result['message']];

        // Mantieni max 20 turni in history
        if (count($history) > 40) {
            $history = array_slice($history, -40);
        }

        // Aggiorna sessione
        $newData = array_merge($session->data ?? [], [
            'history' => $history,
            'state'   => $result['next_state'] ?? $session->state,
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

    private function buildSystemPrompt(BotSession $session, array $slots = []): string
    {
        $profile  = $session->data['profile'] ?? [];
        $state    = $session->state;

        $slotsText = empty($slots)
            ? "Nessuno slot disponibile al momento."
            : collect($slots)->map(fn($s, $i) => ($i+1).". {$s['label']} — €{$s['price']}")->implode("\n");

        return <<<PROMPT
Sei il bot di Le Cercle Tennis Club, un circolo tennistico a San Gennaro Vesuviano (NA).
Parli sempre in italiano, con tono amichevole e diretto.
Il tuo obiettivo è aiutare l'utente a prenotare un campo da tennis.

STATO CORRENTE: {$state}
PROFILO UTENTE: {$this->formatProfile($profile)}

SLOT DISPONIBILI (reali, dal calendario del circolo):
{$slotsText}

FLUSSO DA SEGUIRE:

1. NEW: Dai il benvenuto e chiedi cosa vuole fare. Pulsanti (max 3):
   - "Ho già un avversario"
   - "Trovami un avversario"
   - "Noleggio sparapalline"

2a. Se "Ho già un avversario" o "Noleggio sparapalline":
   Vai direttamente a SCEGLI_DATA. Non raccogliere profilo tennistico.

2b. Se "Trovami un avversario":
   - Chiedi se è tesserato FIT. Pulsanti: ["Sì", "No"]
   - Se SÌ: chiedi la classifica FIT (es. 4.1, 3.3, NC).
   - Se NO: chiedi il livello. Pulsanti: ["Neofita", "Dilettante", "Avanzato"]
   - Poi chiedi l'età (testo libero).
   - Poi chiedi la fascia oraria. Pulsanti: ["Mattina", "Pomeriggio", "Sera"]
   - Poi vai a SCEGLI_DATA.

3. SCEGLI_DATA: Mostra gli slot disponibili qui sopra e chiedi quale preferisce.
   USA ESATTAMENTE gli slot nella lista SLOT DISPONIBILI — non inventarne altri.

4. ATTESA_MATCH: Informa che stai cercando un avversario compatibile.

CORREZIONI DATI:
- Se l'utente dice di aver sbagliato un dato, aggiorna il campo nel profile e conferma.
- Puoi tornare a uno stato precedente se l'utente lo chiede.

REGOLE IMPORTANTI:
- Fai UNA sola domanda alla volta.
- Sii breve e diretto — massimo 3 righe per messaggio.
- Non inventare slot — usa SOLO quelli nella lista sopra.
- Se l'utente ha già un profilo salvato e scrive qualcosa di generico, chiedi direttamente se vuole prenotare.

RISPOSTA: Rispondi SEMPRE e SOLO con un oggetto JSON valido:
{
  "message": "testo da inviare all'utente",
  "next_state": "NEW|ONBOARD_FIT|ONBOARD_ETA|ONBOARD_SLOT|SCEGLI_DATA|SCEGLI_SLOT|ATTESA_MATCH|CONFERMATO",
  "buttons": ["pulsante1", "pulsante2"],
  "profile": {"chiave": "valore"},
  "chosen_slot": null
}

Il campo "chosen_slot" va popolato con l'oggetto slot scelto dall'utente (copia dall'elenco sopra).
PROMPT;
    }

    private function formatProfile(array $profile): string
    {
        if (empty($profile)) return "Nessun dato ancora raccolto.";

        $lines = [];
        if (isset($profile['is_fit']))     $lines[] = "Tesserato FIT: " . ($profile['is_fit'] ? 'sì' : 'no');
        if (isset($profile['fit_rating'])) $lines[] = "Classifica: " . $profile['fit_rating'];
        if (isset($profile['self_level'])) $lines[] = "Livello autodichiarato: " . $profile['self_level'];
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
