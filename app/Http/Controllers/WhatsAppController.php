<?php

namespace App\Http\Controllers;

use App\Models\BotSession;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppController extends Controller
{
    private WhatsAppService $wa;

    public function __construct(WhatsAppService $wa)
    {
        $this->wa = $wa;
    }

    /**
     * Verifica webhook Meta (GET)
     */
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

    /**
     * Riceve messaggi WhatsApp (POST)
     */
    public function handle(Request $request): Response
    {
        $data = $request->all();

        // Estrai il messaggio
        $message = data_get($data, 'entry.0.changes.0.value.messages.0');

        if (!$message) {
            return response('OK', 200);
        }

        $from  = $message['from'];
        $input = $this->parseInput($message);

        // Recupera o crea la sessione
        $session = BotSession::firstOrCreate(
            ['phone' => $from],
            ['state' => 'NEW', 'data' => []]
        );

        // Esegui la state machine
        $result = $this->dispatch($session, $input);

        // Aggiorna la sessione
        $session->update([
            'state' => $result['next_state'],
            'data'  => array_merge($session->data ?? [], $result['data'] ?? []),
        ]);

        // Invia la risposta
        if (!empty($result['buttons'])) {
            $this->wa->sendButtons($from, $result['message'], $result['buttons']);
        } else {
            $this->wa->sendText($from, $result['message']);
        }

        return response('OK', 200);
    }

    /**
     * Estrai il testo o l'ID del pulsante dal messaggio
     */
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

    /**
     * State machine — smista all'handler corretto
     */
    private function dispatch(BotSession $session, string $input): array
    {
        return match($session->state) {
            'NEW'            => $this->handleNew($session, $input),
            'ONBOARD_FIT'    => $this->handleOnboardFit($session, $input),
            'ONBOARD_LIVELLO'=> $this->handleOnboardLivello($session, $input),
            'ONBOARD_ETA'    => $this->handleOnboardEta($session, $input),
            'ONBOARD_SLOT'   => $this->handleOnboardSlot($session, $input),
            'SCEGLI_DATA'    => $this->handleScegliData($session, $input),
            'SCEGLI_SLOT'    => $this->handleScegliSlot($session, $input),
            'CONFERMA_SLOT'  => $this->handleConfermaSlot($session, $input),
            'ATTESA_MATCH'   => $this->handleAttesaMatch($session, $input),
            'SCELTA_PAGAMENTO' => $this->handleSceltaPagamento($session, $input),
            'FEEDBACK'       => $this->handleFeedback($session, $input),
            default          => $this->handleNew($session, $input),
        };
    }

    // ── HANDLERS ─────────────────────────────────────────

    private function handleNew(BotSession $session, string $input): array
    {
        return [
            'next_state' => 'ONBOARD_FIT',
            'message'    => "Ciao! 👋 Benvenuto a Le Cercle Tennis Club.\n\nSono il bot del circolo e ti aiuto a trovare un avversario e prenotare il campo.\n\nSei tesserato FIT?",
            'buttons'    => ['✅ Sì, sono tesserato', '❌ No, non sono tesserato'],
            'data'       => [],
        ];
    }

    private function handleOnboardFit(BotSession $session, string $input): array
    {
        if ($input === 'btn_0') {
            return [
                'next_state' => 'ONBOARD_LIVELLO',
                'message'    => "Perfetto! Qual è la tua classifica FIT?\n\nInserisci la classifica (es. 4.1, 3.3, 2.5, NC):",
                'buttons'    => [],
                'data'       => ['is_fit' => true],
            ];
        }

        return [
            'next_state' => 'ONBOARD_LIVELLO',
            'message'    => "Nessun problema! Come definiresti il tuo livello?",
            'buttons'    => ['🌱 Neofita', '🎾 Dilettante', '⚡ Intermedio', '🏆 Avanzato'],
            'data'       => ['is_fit' => false],
        ];
    }

    private function handleOnboardLivello(BotSession $session, string $input): array
    {
        $levelMap = [
            'btn_0' => [1, 'Neofita'],
            'btn_1' => [2, 'Dilettante'],
            'btn_2' => [3, 'Intermedio'],
            'btn_3' => [4, 'Avanzato'],
        ];

        $data = [];

        if (isset($levelMap[$input])) {
            // Livello da pulsante
            [$level, $label] = $levelMap[$input];
            $data = ['self_level' => $level];
        } else {
            // Classifica FIT da testo
            if (!preg_match('/^(NC|[1-4]\.[1-5])$/i', trim($input))) {
                return [
                    'next_state' => 'ONBOARD_LIVELLO',
                    'message'    => "⚠️ Classifica non valida.\n\nInserisci la classifica FIT nel formato corretto (es. 4.1, 3.3, 2.5, NC):",
                    'buttons'    => [],
                    'data'       => [],
                ];
            }
            $data = ['fit_rating' => strtoupper(trim($input))];
        }

        return [
            'next_state' => 'ONBOARD_ETA',
            'message'    => "Ottimo! Quanti anni hai?\n\nInserisci la tua età:",
            'buttons'    => [],
            'data'       => $data,
        ];
    }

    private function handleOnboardEta(BotSession $session, string $input): array
    {
        $age = intval($input);

        if ($age < 10 || $age > 90) {
            return [
                'next_state' => 'ONBOARD_ETA',
                'message'    => "⚠️ Età non valida.\n\nInserisci la tua età in anni (es. 35):",
                'buttons'    => [],
                'data'       => [],
            ];
        }

        return [
            'next_state' => 'ONBOARD_SLOT',
            'message'    => "Perfetto! Quando preferisci giocare di solito?",
            'buttons'    => ['🌅 Mattina', '☀️ Pomeriggio', '🌙 Sera', '🎾 Qualsiasi'],
            'data'       => ['age' => $age],
        ];
    }

    private function handleOnboardSlot(BotSession $session, string $input): array
    {
        $slotMap = [
            'btn_0' => 'morning',
            'btn_1' => 'afternoon',
            'btn_2' => 'evening',
            'btn_3' => 'any',
        ];

        $slot = $slotMap[$input] ?? 'any';

        // Salva il profilo completo nel DB
        $sessionData = $session->data ?? [];
        $user = \App\Models\User::updateOrCreate(
            ['phone' => $session->phone],
            [
                'name'            => 'Giocatore ' . substr($session->phone, -4),
                'is_fit'          => $sessionData['is_fit'] ?? false,
                'fit_rating'      => $sessionData['fit_rating'] ?? null,
                'self_level'      => $sessionData['self_level'] ?? null,
                'elo_rating'      => 1200,
                'preferred_slots' => [$slot],
                'email'           => $session->phone . '@lecercleclub.it',
                'password'        => bcrypt(\Str::random(16)),
            ]
        );

        return [
            'next_state' => 'SCEGLI_DATA',
            'message'    => "✅ Profilo salvato!\n\nQuando vuoi giocare?\n\nInserisci una data (es. domani, lunedì, 25 marzo) o scrivi *oggi* per vedere i prossimi slot disponibili:",
            'buttons'    => [],
            'data'       => ['user_id' => $user->id, 'preferred_slot' => $slot],
        ];
    }

    private function handleScegliData(BotSession $session, string $input): array
    {
        // Per ora mostriamo slot fittizi — integreremo Google Calendar dopo
        $slots = [
            'Oggi 18:00 — €12.00',
            'Oggi 20:00 — €15.00',
            'Domani 09:00 — €10.00',
            'Domani 18:00 — €12.00',
            'Domani 20:00 — €15.00',
        ];

        return [
            'next_state' => 'SCEGLI_SLOT',
            'message'    => "Ecco i prossimi slot disponibili:\n\n" . collect($slots)->map(fn($s, $i) => ($i+1).". $s")->implode("\n"),
            'buttons'    => array_slice($slots, 0, 3),
            'data'       => ['available_slots' => $slots],
        ];
    }

    private function handleScegliSlot(BotSession $session, string $input): array
    {
        $slots = $session->data['available_slots'] ?? [];
        $index = intval(str_replace('btn_', '', $input));
        $slot  = $slots[$index] ?? $slots[0];

        return [
            'next_state' => 'CONFERMA_SLOT',
            'message'    => "Hai scelto: *{$slot}*\n\nConfermi la prenotazione?",
            'buttons'    => ['✅ Confermo', '🔄 Scegli altro slot'],
            'data'       => ['chosen_slot' => $slot],
        ];
    }

    private function handleConfermaSlot(BotSession $session, string $input): array
    {
        if ($input === 'btn_1') {
            return [
                'next_state' => 'SCEGLI_DATA',
                'message'    => "Nessun problema! Quando vorresti giocare?",
                'buttons'    => [],
                'data'       => [],
            ];
        }

        $slot = $session->data['chosen_slot'] ?? 'slot selezionato';

        return [
            'next_state' => 'ATTESA_MATCH',
            'message'    => "✅ Perfetto! Ho confermato il tuo slot: *{$slot}*\n\nSto cercando un avversario compatibile per te. Ti avviso appena qualcuno accetta! 🎾",
            'buttons'    => [],
            'data'       => [],
        ];
    }

    private function handleAttesaMatch(BotSession $session, string $input): array
    {
        return [
            'next_state' => 'ATTESA_MATCH',
            'message'    => "Sto ancora cercando un avversario per te. Ti avviso appena qualcuno accetta! 🎾",
            'buttons'    => [],
            'data'       => [],
        ];
    }

    private function handleSceltaPagamento(BotSession $session, string $input): array
    {
        if ($input === 'btn_0') {
            return [
                'next_state' => 'CONFERMATO',
                'message'    => "🔗 Ecco il link per pagare online:\n\n[Link Stripe — disponibile a breve]\n\nHai tempo fino a 24h prima del match.",
                'buttons'    => [],
                'data'       => ['payment' => 'online'],
            ];
        }

        return [
            'next_state' => 'CONFERMATO',
            'message'    => "💵 Perfetto! Pagherai di persona al circolo prima del match.\n\nA presto in campo! 🎾",
            'buttons'    => [],
            'data'       => ['payment' => 'in_person'],
        ];
    }

    private function handleFeedback(BotSession $session, string $input): array
    {
        return [
            'next_state' => 'SCEGLI_DATA',
            'message'    => "Grazie per il feedback! Il risultato è stato registrato. 🏆\n\nVuoi prenotare un altro campo?",
            'buttons'    => ['🎾 Sì, prenota', '❌ No, grazie'],
            'data'       => [],
        ];
    }
}
