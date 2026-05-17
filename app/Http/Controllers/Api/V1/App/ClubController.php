<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Club;
use Illuminate\Http\JsonResponse;

class ClubController extends Controller
{
    /**
     * GET /v1/app/club  (pubblico, no auth)
     * L'app lo chiama all'avvio per branding (nome, colori, logo).
     */
    public function show(): JsonResponse
    {
        $club = Club::current();

        return response()->json([
            'id'              => $club->id,
            'slug'            => $club->slug,
            'name'            => $club->name,
            'tagline'         => $club->tagline,
            'logo_url'        => $club->logo_path ? asset('storage/' . $club->logo_path) : null,
            'primary_color'   => $club->primary_color,
            'secondary_color' => $club->secondary_color,
            'accent_color'    => $club->accent_color,
            'address'         => $club->address,
            'phone'           => $club->phone,
            'email'           => $club->email,
            'timezone'        => $club->timezone,
            'settings'        => $club->settings,
        ]);
    }
}
