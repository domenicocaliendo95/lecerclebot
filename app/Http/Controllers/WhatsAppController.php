<?php

namespace App\Http\Controllers;

use App\Models\BotSession;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    private WhatsAppService $wa;
    private GeminiService $gemini;

    public function __construct(WhatsAppService $wa, GeminiService $gemini)
    {
        $this->wa     = $wa;
        $this->gemini = $gemini;
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

        // System prompt
        $systemPrompt = $this->buildSystemPrompt($session);

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

    private function buildSystemPrompt(BotSession $session): string
    {
        $profile = $session->data['profile'] ?? [];
        $state   = $session->state;

        return <<<PROMPT
Sei il bot di Le Cercle Tennis Club, un circolo tennistico a San Gennaro Vesuviano (NA).
Parli sempre in italiano, con tono amichevole e diretto.
Il tuo obiettivo è aiutare l'utente a prenotare un campo da tennis e trovare un avversario.

STATO CORRENTE: {$state}
PROFILO UTENTE: {$this->formatProfile($profile)}

FLUSSO DA SEGUIRE:
1. NEW: Dai il benvenuto e chiedi se è tesserato FIT. Pulsanti: ["Sì", "No"]
2a. Se ha risposto SÌ a FIT: chiedi la classifica FIT (es. 4.1, 3.3, 2.5, NC). NON chiedere il livello autodichiarato.
2b. Se ha risposto NO a FIT: chiedi il livello autodichiarato con questi 4 valori: neofita, dilettante, intermedio, avanzato.
3. ONBOARD_ETA: Chiedi l'età.
4. ONBOARD_SLOT: Chiedi la fascia oraria preferita (mattina/pomeriggio/sera).
5. SCEGLI_DATA: Chiedi quando vuole giocare e mostra slot disponibili.
6. ATTESA_MATCH: Informa che stai cercando un avversario.

REGOLE IMPORTANTI:
- Fai UNA sola domanda alla volta.
- Sii breve e diretto — massimo 3 righe per messaggio.
- Non inventare slot o disponibilità.
- Se l'utente scrive qualcosa di non pertinente, riporta gentilmente al flusso.

RISPOSTA: Rispondi SEMPRE e SOLO con un oggetto JSON valido in questo formato:
{
  "message": "testo da inviare all'utente",
  "next_state": "NEW|ONBOARD_FIT|ONBOARD_ETA|ONBOARD_SLOT|SCEGLI_DATA|ATTESA_MATCH|CONFERMATO",
  "buttons": ["pulsante1", "pulsante2"],
  "profile": {"chiave": "valore"}
}

Il campo "buttons" è opzionale e può avere massimo 3 elementi.
Il campo "profile" contiene i dati raccolti in questo turno (es. {"is_fit": true, "fit_rating": "4.1"}).
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
