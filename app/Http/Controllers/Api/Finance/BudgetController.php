<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BudgetController extends Controller
{
    public function index()
    {
        return response()->json(
            Budget::orderBy('category')->get()
        );
    }

    public function store(Request $request)
    {
        $budget = Budget::create([
            'id' => Str::uuid(),
            'category' => $request->category,
            'monthly_limit' => $request->monthly_limit,
            'notes' => $request->notes,
            'is_active' => true,
        ]);

        return response()->json($budget);
    }

    public function show(Budget $budget)
    {
        return response()->json($budget);
    }

    public function update(
        Request $request,
        Budget $budget
    ) {
        $budget->update([
            'category' =>
                $request->category,
            'monthly_limit' =>
                $request->monthly_limit,
            'notes' =>
                $request->notes,
        ]);

        return response()->json(
            $budget->fresh()
        );
    }

    public function destroy(
        Budget $budget
    ) {
        $budget->delete();

        return response()->json([
            'success' => true
        ]);
    }
}