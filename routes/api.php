<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// Verifica webhook Meta (GET)
Route::get('/webhook', function (Illuminate\Http\Request $request) {
    $verify_token = env('WHATSAPP_VERIFY_TOKEN');
    
    $mode      = $request->query('hub_mode');
    $token     = $request->query('hub_verify_token');
    $challenge = $request->query('hub_challenge');
    
    if ($mode === 'subscribe' && $token === $verify_token) {
        return response($challenge, 200);
    }
    
    return response('Forbidden', 403);
});

// Riceve messaggi WhatsApp (POST)
Route::post('/webhook', function (Illuminate\Http\Request $request) {
    \Log::info('WhatsApp webhook received', $request->all());
    return response('OK', 200);
});