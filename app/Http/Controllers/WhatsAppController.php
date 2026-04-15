<?php

namespace App\Http\Controllers;

use App\Services\Flow\FlowRunner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller sottile: valida il webhook e delega tutto al FlowRunner.
 *
 * Nota architetturale: questo controller è temporaneamente WhatsApp-specifico,
 * ma il FlowRunner sottostante è agnostico al canale. Quando introdurremo
 * ChannelAdapter, questo controller diventerà un adapter tra i molti possibili.
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly FlowRunner $runner,
    ) {}

    /* ───────── Verifica webhook Meta ───────── */

    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode'  => $mode,
            'token' => $token ? '[REDACTED]' : null,
        ]);

        return response('Forbidden', 403);
    }

    /* ───────── Gestione messaggi in arrivo ───────── */

    public function handle(Request $request): Response
    {
        // Rispondi sempre 200 a Meta per evitare retry
        try {
            $data    = $request->all();
            $message = data_get($data, 'entry.0.changes.0.value.messages.0');

            if (!$message) {
                return response('OK', 200);
            }

            $from  = data_get($message, 'from');
            $input = $this->extractInput($message);

            if (empty($from) || empty($input)) {
                return response('OK', 200);
            }

            $this->runner->process($from, $input);
        } catch (\Throwable $e) {
            // Log ma rispondi comunque 200 — Meta non deve riprovare
            Log::error('WhatsApp webhook handler error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('OK', 200);
    }

    /* ───────── Estrazione input dal messaggio ───────── */

    private function extractInput(array $message): string
    {
        $type = data_get($message, 'type', '');

        return match ($type) {
            'text'        => trim(data_get($message, 'text.body', '')),
            'interactive' => trim(
                data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.button_reply.id')
                ?? data_get($message, 'interactive.list_reply.title')
                ?? data_get($message, 'interactive.list_reply.id')
                ?? ''
            ),
            default => '',
        };
    }
}
