<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    public function __construct(
        private WhatsAppService $wa
    ) {}

    /**
     * Genera e invia un OTP via WhatsApp (con fallback log in dev).
     *
     * @return array{otp_id:int, expires_at:string, masked_phone:string, resend_available_at:string}
     */
    public function request(string $phone, string $ip, ?string $purpose = 'login'): array
    {
        $phone = $this->normalizePhone($phone);

        // Riusa OTP attivo se esiste
        $existing = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', OtpCode::MAX_ATTEMPTS)
            ->latest()
            ->first();

        if ($existing) {
            return $this->responseFor($existing, $phone);
        }

        // Genera nuovo codice 6 cifre crypto-safe
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            'phone'      => $phone,
            'code_hash'  => Hash::make($code),
            'purpose'    => $purpose,
            'channel'    => 'whatsapp',
            'expires_at' => now()->addMinutes(OtpCode::TTL_MINUTES),
            'ip'         => $ip,
        ]);

        $this->dispatch($phone, $code);

        return $this->responseFor($otp, $phone);
    }

    /**
     * Verifica il codice. Ritorna l'OTP consumato se valido, altrimenti null.
     */
    public function verify(string $phone, string $code): ?OtpCode
    {
        $phone = $this->normalizePhone($phone);

        $otp = OtpCode::where('phone', $phone)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp || $otp->isLocked()) {
            return null;
        }

        if (!$otp->verify($code)) {
            $otp->incrementAttempts();
            return null;
        }

        $otp->markConsumed();
        return $otp;
    }

    /**
     * Manda lo stesso codice via email come canale di fallback.
     * NB: l'OTP esistente viene riusato, solo il canale di consegna cambia.
     */
    public function resendViaEmail(string $phone, string $email): bool
    {
        $phone = $this->normalizePhone($phone);

        $otp = OtpCode::where('phone', $phone)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            return false;
        }

        // Non possiamo recuperare il codice in chiaro (è hashato).
        // Generiamo un nuovo codice mantenendo lo stesso otp_id.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp->code_hash = Hash::make($code);
        $otp->channel = 'email';
        $otp->save();

        try {
            Mail::raw(
                "Il tuo codice di accesso a Le Cercle Club è: {$code}\nScade tra 5 minuti.",
                fn($m) => $m->to($email)->subject('Il tuo codice Le Cercle')
            );
            return true;
        } catch (\Throwable $e) {
            Log::error('OTP email send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Pulizia OTP scaduti / consumati > 24h. Chiamata da cron.
     */
    public function cleanup(): int
    {
        return OtpCode::where('created_at', '<', now()->subDay())->delete();
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function dispatch(string $phone, string $code): void
    {
        // Whitelist test phones: invio via sendText (no template).
        // Funziona solo se il numero è in finestra 24h col bot.
        $testPhones = collect(explode(',', (string) config('services.otp.test_phones', '')))
            ->map(fn($p) => trim($p))
            ->filter()
            ->all();

        if (in_array($phone, $testPhones, true)) {
            try {
                $this->wa->sendText(
                    $phone,
                    "🔐 Codice di accesso Le Cercle Club: *{$code}*\n\nScade tra 5 minuti."
                );
                Log::info('[OTP-TEST] Codice inviato via WA text', ['phone' => $phone, 'code' => $code]);
                return;
            } catch (\Throwable $e) {
                Log::warning('[OTP-TEST] WA text failed, fallback to log', [
                    'phone' => $phone,
                    'code'  => $code,
                    'error' => $e->getMessage(),
                ]);
                return;
            }
        }

        $driver = config('services.otp.driver', 'whatsapp');

        if ($driver === 'log') {
            Log::info('[OTP-DEV] Codice generato', ['phone' => $phone, 'code' => $code]);
            return;
        }

        $templateName = config('services.otp.template_name', 'lecercle_auth_otp');
        $lang         = config('services.otp.template_lang', 'it');

        try {
            $this->wa->sendTemplate($phone, $templateName, [$code], $lang);
        } catch (\Throwable $e) {
            Log::error('OTP WA send failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    private function normalizePhone(string $phone): string
    {
        // Strip tutto tranne cifre, poi prepend "+" se manca.
        $clean = preg_replace('/\D/', '', $phone);
        return '+' . $clean;
    }

    private function responseFor(OtpCode $otp, string $phone): array
    {
        return [
            'otp_id'              => $otp->id,
            'expires_at'          => $otp->expires_at->toIso8601String(),
            'masked_phone'        => $this->maskPhone($phone),
            'resend_available_at' => $otp->created_at->addSeconds(60)->toIso8601String(),
        ];
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) return $phone;
        $visible = mb_substr($phone, 0, 4) . str_repeat('•', strlen($phone) - 8) . mb_substr($phone, -4);
        return $visible;
    }
}
