<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Support\FinanceTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function index()
    {
        $budgets = Budget::orderBy('category')
            ->get()
            ->map(fn (Budget $budget) => FinanceTransformer::budget($budget));

        return response()->json($budgets);
    }

    public function store(Request $request)
    {
        // finance_budgets punya UNIQUE KEY (user_id, category) — sekarang user_id selalu
        // diisi 1, jadi constraint itu benar-benar aktif. Divalidasi dulu di sini supaya
        // kategori duplikat menghasilkan pesan 422 yang jelas, bukan 500 dari MySQL.
        $request->validate([
            'category' => ['required', 'string', 'max:60', Rule::unique('finance_budgets', 'category')->where(fn ($q) => $q->where('user_id', 1))],
            'monthly_limit' => ['required', 'numeric'],
        ]);

        $budget = Budget::create([
            'id' => Str::uuid(),
            'user_id' => 1,
            'category' => $request->category,
            'monthly_limit' => $request->monthly_limit,
            'notes' => $request->notes,
            'is_active' => true,
        ]);

        return response()->json(FinanceTransformer::budget($budget));
    }

    public function show(Budget $budget)
    {
        return response()->json(FinanceTransformer::budget($budget));
    }

    public function update(
        Request $request,
        Budget $budget
    ) {
        $request->validate([
            'category' => ['required', 'string', 'max:60', Rule::unique('finance_budgets', 'category')->where(fn ($q) => $q->where('user_id', 1))->ignore($budget->id)],
            'monthly_limit' => ['required', 'numeric'],
        ]);

        $budget->update([
            'category' =>
                $request->category,
            'monthly_limit' =>
                $request->monthly_limit,
            'notes' =>
                $request->notes,
        ]);

        return response()->json(
            FinanceTransformer::budget($budget->fresh())
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