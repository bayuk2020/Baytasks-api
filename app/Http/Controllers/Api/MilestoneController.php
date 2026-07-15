<?php

namespace App\Http\Controllers\Api; // <-- Namespace folder Api

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class MilestoneController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'goal_id'      => 'required|integer',
            'name'         => 'required|string|max:255',
            'target_value' => 'numeric',
            'current_value'=> 'numeric',
            'due_date'     => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $milestone = Milestone::create([
            'goal_id'       => $request->input('goal_id'),
            'name'          => $request->input('name'),
            'target_value'  => $request->input('target_value', 0),
            'current_value' => $request->input('current_value', 0),
            'due_date'      => $request->input('due_date'),
            'weight'        => $request->input('weight', 1),
            'completed'     => $request->input('completed', 0),
        ]);

        return response()->json([
            'message'   => 'Milestone created successfully!',
            'milestone' => $milestone
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $milestone = Milestone::find($id);

        if (!$milestone) {
            return response()->json(['message' => 'Milestone not found'], 404);
        }

        $inputs = $request->only(['name', 'target_value', 'current_value', 'due_date', 'weight', 'completed']);
        $milestone->update($inputs);

        return response()->json([
            'message'   => 'Milestone updated successfully!',
            'milestone' => $milestone
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $milestone = Milestone::find($id);

        if (!$milestone) {
            return response()->json(['message' => 'Milestone not found'], 404);
        }

        $milestone->delete();

        return response()->json(['message' => 'Milestone deleted permanently!']);
    }
}