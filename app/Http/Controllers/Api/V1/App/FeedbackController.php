<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /**
     * POST /v1/app/feedback
     * Body: { rating: 1-5, comment?, type?: 'post_match'|'spontaneous', booking_id? }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:1000',
            'type'       => 'sometimes|in:post_match,spontaneous',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $user = $request->user();

        $feedback = Feedback::create([
            'user_id'    => $user->id,
            'booking_id' => $data['booking_id'] ?? null,
            'type'       => $data['type'] ?? ($data['booking_id'] ? 'post_match' : 'spontaneous'),
            'rating'     => $data['rating'],
            'content'    => $data['comment'] ? ['text' => $data['comment']] : null,
            'metadata'   => ['source' => 'app'],
        ]);

        return response()->json([
            'data' => [
                'id'         => $feedback->id,
                'rating'     => $feedback->rating,
                'comment'    => $data['comment'] ?? null,
                'created_at' => $feedback->created_at->toIso8601String(),
            ],
        ], 201);
    }
}
