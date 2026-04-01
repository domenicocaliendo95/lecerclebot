<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
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
