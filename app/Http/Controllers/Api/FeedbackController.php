<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Feedback::with(['user', 'booking'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('rating')) {
            $query->where('rating', $request->input('rating'));
        }
        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $feedbacks = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $feedbacks->map(fn(Feedback $f) => [
                'id'         => $f->id,
                'rating'     => $f->rating,
                'content'    => $f->content,
                'type'       => $f->type,
                'is_read'    => $f->is_read,
                'created_at' => $f->created_at?->toIso8601String(),
                'user'       => $f->user ? ['id' => $f->user->id, 'name' => $f->user->name, 'phone' => $f->user->phone] : null,
                'booking'    => $f->booking ? [
                    'id'   => $f->booking->id,
                    'date' => $f->booking->booking_date?->format('Y-m-d'),
                    'time' => substr($f->booking->start_time ?? '', 0, 5),
                ] : null,
            ]),
            'meta' => [
                'current_page' => $feedbacks->currentPage(),
                'last_page'    => $feedbacks->lastPage(),
                'total'        => $feedbacks->total(),
            ],
            'stats' => [
                'total'    => Feedback::count(),
                'unread'   => Feedback::where('is_read', false)->count(),
                'avg'      => round((float) Feedback::avg('rating'), 1),
                'by_rating'=> Feedback::selectRaw('rating, count(*) as count')->groupBy('rating')->orderBy('rating')->pluck('count', 'rating'),
            ],
        ]);
    }

    public function markRead(Feedback $feedback): JsonResponse
    {
        $feedback->update(['is_read' => true]);
        return response()->json(['ok' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        Feedback::where('is_read', false)->update(['is_read' => true]);
        return response()->json(['ok' => true]);
    }
}
