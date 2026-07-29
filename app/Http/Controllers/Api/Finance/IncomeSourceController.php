<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\IncomeSource;
use App\Support\FinanceTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IncomeSourceController extends Controller
{
    public function index()
    {
        $sources = IncomeSource::orderBy('name')
            ->get()
            ->map(fn (IncomeSource $source) => FinanceTransformer::incomeSource($source));

        return response()->json($sources);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:finance_income_sources,name'],
            'color' => ['nullable', 'string', 'max:100'],
        ]);

        $source = IncomeSource::create([
            'id' => (string) Str::uuid(),
            'user_id' => 1,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? 'oklch(0.72 0.16 230)',
            'is_active' => true,
        ]);

        return response()->json(
            FinanceTransformer::incomeSource($source),
            201
        );
    }

    public function update(
        Request $request,
        IncomeSource $incomeSource
    ) {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('finance_income_sources', 'name')->ignore($incomeSource->id),
            ],
            'color' => ['sometimes', 'required', 'string', 'max:100'],
        ]);

        $incomeSource->update($validated);

        return response()->json(
            FinanceTransformer::incomeSource($incomeSource->fresh())
        );
    }

    public function destroy(
        IncomeSource $incomeSource
    ) {
        $incomeSource->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
