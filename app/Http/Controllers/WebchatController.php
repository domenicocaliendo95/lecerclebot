<?php

namespace App\Http\Controllers;

use App\Services\Flow\FlowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint inbound/outbound per il canale webchat.
 *
 * Il client (widget JS, app, tester) genera un `session` (UUID in
 * localStorage), poi:
 *   - POST /api/webchat/message {session, text}  → FlowRunner processa
 *   - GET  /api/webchat/poll?session=...          → legge la coda outbox
 *
 * Il polling è la scelta più semplice per MVP; upgrade a SSE/WebSocket è
 * trasparente per i moduli (niente da cambiare nel core, solo nel WebchatAdapter).
 */
class WebchatController extends Controller
{
    public function __construct(private readonly FlowRunner $runner) {}

    public function inbound(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:128',
            'text'    => 'required|string',
        ]);

        $this->runner->process('webchat', $data['session'], $data['text']);

        return response()->json(['ok' => true]);
    }

    public function poll(Request $request): JsonResponse
    {
        $session = (string) $request->query('session', '');
        if ($session === '') {
            return response()->json(['messages' => []]);
        }

        $rows = DB::table('webchat_outbox')
            ->where('external_id', $session)
            ->whereNull('delivered_at')
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['messages' => []]);
        }

        $ids = $rows->pluck('id')->all();
        DB::table('webchat_outbox')->whereIn('id', $ids)->update(['delivered_at' => now()]);

        return response()->json([
            'messages' => $rows->map(fn($r) => json_decode($r->payload, true))->values(),
        ]);
    }
}
