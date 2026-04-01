<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
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

    /**
     * Ultimi giocatori registrati (per dashboard).
     */
    public function latest(): AnonymousResourceCollection
    {
        $users = User::where('is_admin', false)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return UserResource::collection($users);
    }
}
