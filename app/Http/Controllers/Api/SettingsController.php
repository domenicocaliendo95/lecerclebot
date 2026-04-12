<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function env(): JsonResponse
    {
        return response()->json([
            'whatsapp_phone_number_id' => config('services.whatsapp.phone_number_id'),
            'whatsapp_verify_token'    => config('services.whatsapp.verify_token'),
            'gemini_model'             => config('services.gemini.model'),
            'google_calendar_id'       => config('services.google_calendar.calendar_id'),
            'app_timezone'             => config('app.timezone'),
            'whatsapp_token'           => $this->maskSecret(config('services.whatsapp.api_token')),
            'gemini_key'               => $this->maskSecret(config('services.gemini.api_key')),
        ]);
    }

    private function maskSecret(?string $value): string
    {
        if (empty($value)) {
            return '(non configurato)';
        }

        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . substr($value, -4);
    }

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
        $request->validate([
            'value' => 'required',
        ]);

        BotSetting::set($key, $request->input('value'));

        return response()->json(['key' => $key, 'value' => $request->input('value')]);
    }
}
