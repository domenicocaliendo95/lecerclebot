<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /**
     * POST /v1/app/auth/request-otp
     * Body: { phone: "+39..." }
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => ['code' => 'invalid_phone', 'message' => 'Numero non valido', 'details' => $validator->errors()],
            ], 422);
        }

        $phone = $request->input('phone');
        $ip    = $request->ip() ?? '0.0.0.0';

        // Rate limit per phone (3/min) e per IP (10/min)
        $phoneKey = 'otp-req-phone:' . preg_replace('/\D/', '', $phone);
        $ipKey    = 'otp-req-ip:' . $ip;

        if (RateLimiter::tooManyAttempts($phoneKey, 3)) {
            return response()->json([
                'error' => [
                    'code'    => 'rate_limited',
                    'message' => 'Troppe richieste per questo numero. Riprova tra poco.',
                    'retry_after_seconds' => RateLimiter::availableIn($phoneKey),
                ],
            ], 429);
        }

        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return response()->json([
                'error' => [
                    'code'    => 'rate_limited',
                    'message' => 'Troppe richieste da questo dispositivo.',
                    'retry_after_seconds' => RateLimiter::availableIn($ipKey),
                ],
            ], 429);
        }

        RateLimiter::hit($phoneKey, 60);
        RateLimiter::hit($ipKey, 60);

        $result = $this->otp->request($phone, $ip);

        return response()->json($result, 200);
    }

    /**
     * POST /v1/app/auth/verify-otp
     * Body: { phone, code }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:20',
            'code'  => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => ['code' => 'invalid_input', 'message' => 'Input non valido'],
            ], 422);
        }

        $phone = $request->input('phone');
        $code  = $request->input('code');

        $otp = $this->otp->verify($phone, $code);

        if (!$otp) {
            return response()->json([
                'error' => ['code' => 'invalid_code', 'message' => 'Codice non valido o scaduto'],
            ], 422);
        }

        // Normalizza phone come l'OTP service
        $normalizedPhone = '+' . preg_replace('/\D/', '', $phone);

        // Trova o crea utente
        $user = User::where('phone', $normalizedPhone)->first();
        $isNew = false;

        if (!$user) {
            $user = User::create([
                'name'     => 'Nuovo Giocatore',
                'phone'    => $normalizedPhone,
                'email'    => 'app_' . substr(md5($normalizedPhone), 0, 12) . '@lecercleclub.bot',
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
            ]);
            $isNew = true;
        }

        // Blocca admin
        if ($user->is_admin) {
            return response()->json([
                'error' => [
                    'code'    => 'admin_not_allowed_on_app',
                    'message' => "Il tuo account è amministratore. Accedi dal pannello web.",
                ],
            ], 403);
        }

        // Genera token Sanctum
        $deviceName = $request->input('device_name', 'mobile');
        $token = $user->createToken($deviceName, ['*'])->plainTextToken;

        return response()->json([
            'token'                => $token,
            'user'                 => $this->serializeUser($user),
            'is_new'               => $isNew,
            'needs_app_onboarding' => is_null($user->app_onboarded_at),
        ], 200);
    }

    /**
     * POST /v1/app/auth/request-otp-email
     * Fallback se WA non arriva. Richiede phone + email.
     */
    public function requestOtpEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:20',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => ['code' => 'invalid_input', 'message' => 'Input non valido'],
            ], 422);
        }

        $sent = $this->otp->resendViaEmail($request->input('phone'), $request->input('email'));

        if (!$sent) {
            return response()->json([
                'error' => ['code' => 'no_active_otp', 'message' => 'Nessun OTP attivo. Richiedine uno nuovo.'],
            ], 410);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * POST /v1/app/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['ok' => true]);
    }

    private function serializeUser(User $user): array
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
