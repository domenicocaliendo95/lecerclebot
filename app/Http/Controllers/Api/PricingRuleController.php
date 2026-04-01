<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PricingRuleResource;
use App\Models\PricingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request): PricingRuleResource
    {
        $validated = $request->validate([
            'label'            => 'nullable|string|max:100',
            'day_of_week'      => 'nullable|integer|min:0|max:6',
            'specific_date'    => 'nullable|date',
            'start_time'       => 'required|date_format:H:i',
            'end_time'         => 'required|date_format:H:i|after:start_time',
            'duration_minutes' => 'nullable|integer|in:60,90,120,180',
            'price'            => 'nullable|numeric|min:0',
            'price_per_hour'   => 'nullable|numeric|min:0',
            'is_peak'          => 'boolean',
            'is_active'        => 'boolean',
            'priority'         => 'integer|min:0',
        ]);

        $rule = PricingRule::create($validated);

        return new PricingRuleResource($rule);
    }

    public function update(Request $request, PricingRule $pricingRule): PricingRuleResource
    {
        $validated = $request->validate([
            'label'            => 'nullable|string|max:100',
            'day_of_week'      => 'nullable|integer|min:0|max:6',
            'specific_date'    => 'nullable|date',
            'start_time'       => 'sometimes|date_format:H:i',
            'end_time'         => 'sometimes|date_format:H:i',
            'duration_minutes' => 'nullable|integer|in:60,90,120,180',
            'price'            => 'nullable|numeric|min:0',
            'price_per_hour'   => 'nullable|numeric|min:0',
            'is_peak'          => 'sometimes|boolean',
            'is_active'        => 'sometimes|boolean',
            'priority'         => 'sometimes|integer|min:0',
        ]);

        $pricingRule->update($validated);

        return new PricingRuleResource($pricingRule->fresh());
    }

    public function destroy(PricingRule $pricingRule): JsonResponse
    {
        $pricingRule->delete();
        return response()->json(['message' => 'Regola eliminata.']);
    }
}
