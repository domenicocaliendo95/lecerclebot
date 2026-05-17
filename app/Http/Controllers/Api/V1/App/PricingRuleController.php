<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\PricingRule;
use Illuminate\Http\JsonResponse;

class PricingRuleController extends Controller
{
    /**
     * GET /v1/app/pricing-rules
     * Read-only — l'app le usa per mostrare prezzi in fase di prenotazione.
     */
    public function index(): JsonResponse
    {
        $rules = PricingRule::orderBy('day_of_week')->orderBy('start_time')->get();

        return response()->json(['data' => $rules]);
    }
}
