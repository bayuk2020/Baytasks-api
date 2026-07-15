<?php

namespace App\Http\Controllers\Api; // <-- Namespace folder Api

use App\Http\Controllers\Controller;
use App\Models\QuarterlyPlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class QuarterlyPlanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'goal_id'       => 'required|integer',
            'quarter'       => 'required|integer|between:1,4',
            'year'          => 'required|integer',
            'target_amount' => 'numeric',
            'current_amount'=> 'numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $plan = QuarterlyPlan::create([
            'goal_id'        => $request->input('goal_id'),
            'quarter'        => $request->input('quarter'),
            'year'           => $request->input('year'),
            'target_amount'  => $request->input('target_amount', 0),
            'current_amount' => $request->input('current_amount', 0),
            'completed'      => $request->input('completed', 0),
        ]);

        return response()->json([
            'message' => 'Quarterly plan created successfully!',
            'plan'    => $plan
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $plan = QuarterlyPlan::find($id);

        if (!$plan) {
            return response()->json(['message' => 'Quarterly plan not found'], 404);
        }

        $inputs = $request->only(['target_amount', 'current_amount', 'completed']);
        
        if (isset($inputs['current_amount']) && isset($inputs['target_amount'])) {
            if ($inputs['current_amount'] >= $inputs['target_amount'] && $inputs['target_amount'] > 0) {
                $inputs['completed'] = 1;
            }
        }

        $plan->update($inputs);

        return response()->json([
            'message' => 'Quarterly plan updated successfully!',
            'plan'    => $plan
        ]);
    }
}