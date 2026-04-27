<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Restituisce tutti i parametri di configurazione con i valori attuali.
     * Il valore effettivo è: override da bot_settings > config() > fallback.
     */
    public function env(): JsonResponse
    {
        return response()->json([
            'whatsapp_phone_number_id' => $this->getConfig('whatsapp_phone_number_id', 'services.whatsapp.phone_id'),
            'whatsapp_verify_token'    => $this->getConfig('whatsapp_verify_token', 'services.whatsapp.verify_token'),
            'whatsapp_token'           => $this->getConfig('whatsapp_token', 'services.whatsapp.api_token'),
            'whatsapp_api_version'     => $this->getConfig('whatsapp_api_version', 'services.whatsapp.api_version', 'v21.0'),
            'gemini_model'             => $this->getConfig('gemini_model', 'services.gemini.model', 'gemini-2.5-flash'),
            'gemini_key'               => $this->getConfig('gemini_key', 'services.gemini.api_key'),
            'gemini_timeout'           => $this->getConfig('gemini_timeout', 'services.gemini.timeout', '15'),
            'google_calendar_id'       => $this->getConfig('google_calendar_id', 'services.google_calendar.calendar_id'),
            'app_timezone'             => $this->getConfig('app_timezone', 'app.timezone', 'Europe/Rome'),
            'session_timeout_minutes'  => BotSetting::get('session_timeout_minutes', 120),
            'admin_phone'              => BotSetting::get('admin_phone', ''),
        ]);
    }

    /**
     * Aggiorna uno o più parametri ENV. Salva in bot_settings come override.
     */
    public function updateEnv(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_phone_number_id' => 'nullable|string|max:100',
            'whatsapp_verify_token'    => 'nullable|string|max:200',
            'whatsapp_token'           => 'nullable|string|max:500',
            'whatsapp_api_version'     => 'nullable|string|max:20',
            'gemini_model'             => 'nullable|string|max:100',
            'gemini_key'               => 'nullable|string|max:500',
            'gemini_timeout'           => 'nullable|integer|min:1|max:120',
            'google_calendar_id'       => 'nullable|string|max:200',
            'app_timezone'             => 'nullable|string|max:100',
            'session_timeout_minutes'  => 'nullable|integer|min:0|max:10080',
            'admin_phone'              => 'nullable|string|max:20',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null && $value !== '' && !in_array($key, ['session_timeout_minutes', 'admin_phone'])) {
                BotSetting::set("env_{$key}", $value);
            }
        }

        // Campi diretti (non prefissati env_)
        if (array_key_exists('session_timeout_minutes', $validated) && $validated['session_timeout_minutes'] !== null) {
            BotSetting::set('session_timeout_minutes', (int) $validated['session_timeout_minutes']);
        }
        if (array_key_exists('admin_phone', $validated)) {
            BotSetting::set('admin_phone', $validated['admin_phone'] ?: null);
        }

        // Pulisci la cache config di Laravel per applicare subito
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return response()->json(['message' => 'Impostazioni aggiornate.']);
    }

    /**
     * Legge un valore config: prima override da bot_settings, poi config(), poi fallback.
     */
    private function getConfig(string $settingKey, string $configKey, string $fallback = ''): mixed
    {
        // Override da bot_settings
        $override = BotSetting::get("env_{$settingKey}");
        if ($override !== null) {
            return $override;
        }

        return config($configKey, $fallback) ?? $fallback;
    }

    // ── Standard bot_settings CRUD (legacy) ──────────────────────

    public function index(): JsonResponse
    {
        $settings = BotSetting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    public function show(string $key): JsonResponse
    {
        $setting = BotSetting::find($key);
        if (!$setting) {
            return response()->json(['message' => 'Setting non trovato.'], 404);
        }
        return response()->json(['key' => $key, 'value' => $setting->value]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $request->validate(['value' => 'required']);
        BotSetting::set($key, $request->input('value'));
        return response()->json(['key' => $key, 'value' => $request->input('value')]);
    }
}
