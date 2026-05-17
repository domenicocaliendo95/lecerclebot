<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MeController extends Controller
{
    /**
     * GET /v1/app/me
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json($this->serialize($user));
    }

    /**
     * PATCH /v1/app/me
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'                 => 'sometimes|string|min:2|max:60',
            'bio'                  => 'sometimes|nullable|string|max:200',
            'birthdate'            => 'sometimes|nullable|date',
            'is_fit'               => 'sometimes|boolean',
            'fit_rating'           => 'sometimes|nullable|string',
            'self_level'           => 'sometimes|nullable|integer|min:1|max:5',
            'preferred_slots'      => 'sometimes|array',
            'privacy_profile'      => 'sometimes|in:public,club_only,friends_only',
            'show_in_matchmaking'  => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => ['code' => 'invalid_input', 'details' => $validator->errors()]], 422);
        }

        $user = $request->user();
        $user->fill($validator->validated());
        $user->save();

        return response()->json($this->serialize($user));
    }

    /**
     * PATCH /v1/app/me/notification-preferences
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $prefs = $request->validate([
            'reminders'       => 'boolean',
            'match_invites'   => 'boolean',
            'results_request' => 'boolean',
            'chat'            => 'boolean',
            'social'          => 'boolean',
            'tournaments'     => 'boolean',
            'marketing'       => 'boolean',
        ]);

        $user = $request->user();
        $user->notification_preferences = array_merge($user->notification_preferences ?? [], $prefs);
        $user->save();

        return response()->json([
            'notification_preferences' => $user->notification_preferences,
        ]);
    }

    /**
     * Marca app_onboarded_at — chiamato a fine del mini-onboarding.
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->app_onboarded_at = now();
        $user->save();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /v1/app/me/devices  — registra push token
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expo_push_token' => 'required|string',
            'platform'        => 'required|in:ios,android',
            'device_name'     => 'nullable|string|max:100',
            'app_version'     => 'nullable|string|max:20',
        ]);

        DeviceToken::updateOrCreate(
            ['expo_push_token' => $validated['expo_push_token']],
            array_merge($validated, [
                'user_id'      => $request->user()->id,
                'last_used_at' => now(),
            ])
        );

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /v1/app/me/devices/{token}
     */
    public function unregisterDevice(Request $request, string $token): JsonResponse
    {
        DeviceToken::where('user_id', $request->user()->id)
            ->where('expo_push_token', $token)
            ->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /v1/app/me — soft delete account
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete(); // soft delete
        return response()->json(['ok' => true]);
    }

    private function serialize($user): array
    {
        return [
            'id'                       => $user->id,
            'name'                     => $user->name,
            'phone'                    => $user->phone,
            'email'                    => $user->email,
            'avatar_url'               => $user->avatar_path ? asset('storage/' . $user->avatar_path) : null,
            'bio'                      => $user->bio,
            'birthdate'                => $user->birthdate?->toDateString(),
            'is_fit'                   => $user->is_fit,
            'fit_rating'               => $user->fit_rating,
            'self_level'               => $user->self_level,
            'elo_rating'               => $user->elo_rating,
            'matches_played'           => $user->matches_played,
            'matches_won'              => $user->matches_won,
            'preferred_slots'          => $user->preferred_slots,
            'notification_preferences' => $user->notification_preferences,
            'privacy_profile'          => $user->privacy_profile,
            'show_in_matchmaking'      => $user->show_in_matchmaking,
            'app_onboarded_at'         => $user->app_onboarded_at?->toIso8601String(),
        ];
    }
}
