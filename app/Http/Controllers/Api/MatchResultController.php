<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResultResource;
use App\Models\MatchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchResultController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MatchResult::with(['booking.player1', 'booking.player2', 'winner'])
            ->orderByDesc('created_at');

        return MatchResultResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    public function show(MatchResult $matchResult): MatchResultResource
    {
        return new MatchResultResource(
            $matchResult->load(['booking.player1', 'booking.player2', 'winner'])
        );
    }
}
