<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::where('is_admin', false)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('level')) {
            $query->where('self_level', $request->input('level'));
        }

        if ($request->filled('is_fit')) {
            $query->where('is_fit', $request->boolean('is_fit'));
        }

        $sortBy = $request->input('sort', 'name');
        if (in_array($sortBy, ['name', 'elo_rating', 'matches_played', 'created_at'])) {
            $query->reorder($sortBy, $request->input('dir', $sortBy === 'name' ? 'asc' : 'desc'));
        }

        return UserResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'name'             => 'sometimes|string|max:60',
            'phone'            => 'sometimes|string|max:20',
            'is_fit'           => 'sometimes|boolean',
            'fit_rating'       => 'nullable|string|max:10',
            'self_level'       => 'nullable|in:neofita,dilettante,avanzato',
            'age'              => 'nullable|integer|min:5|max:99',
            'elo_rating'       => 'sometimes|integer|min:0',
            'preferred_slots'  => 'nullable|array',
        ]);

        $user->update($validated);

        return new UserResource($user->fresh());
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(['message' => 'Giocatore eliminato.']);
    }

    /**
     * Ricerca veloce per autocomplete (max 10 risultati).
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $q = $request->input('q', '');

        $users = User::where('is_admin', false)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        return UserResource::collection($users);
    }

    public function latest(): AnonymousResourceCollection
    {
        $users = User::where('is_admin', false)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return UserResource::collection($users);
    }
}
