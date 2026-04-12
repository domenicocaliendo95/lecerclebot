<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BotSessionResource;
use App\Models\BotSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BotSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BotSession::orderByDesc('updated_at');

        if ($request->filled('state')) {
            $query->where('state', $request->input('state'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.profile.name')) LIKE ?", ["%{$search}%"]);
            });
        }

        return BotSessionResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    public function show(BotSession $botSession): BotSessionResource
    {
        return new BotSessionResource($botSession);
    }

    /**
     * Elimina la sessione. Il prossimo messaggio dell'utente
     * ripartirà da zero (NEW se non registrato, MENU se registrato).
     */
    public function destroy(BotSession $botSession): \Illuminate\Http\JsonResponse
    {
        $phone = $botSession->phone;
        $botSession->delete();

        return response()->json(['message' => "Sessione {$phone} eliminata."]);
    }
}
