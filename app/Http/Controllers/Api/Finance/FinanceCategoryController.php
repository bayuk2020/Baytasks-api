<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceCategory;
use App\Support\FinanceTransformer;

class FinanceCategoryController extends Controller
{
    public function index()
    {
        $categories = FinanceCategory::orderBy('type')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (FinanceCategory $category) => FinanceTransformer::category($category));

        return response()->json($categories);
    }
}
