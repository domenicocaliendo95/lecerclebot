<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PricingRuleResource;
use App\Models\PricingRule;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PricingRuleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $rules = PricingRule::where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        return PricingRuleResource::collection($rules);
    }
}
