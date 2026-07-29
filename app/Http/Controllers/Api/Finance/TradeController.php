<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Support\FinanceTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TradeController extends Controller
{
    public function index()
    {
        $trades = Trade::latest('opened_at')
            ->get()
            ->map(fn (Trade $trade) => FinanceTransformer::trade($trade));

        return response()->json($trades);
    }

    public function store(Request $request)
    {
        $trade = Trade::create([
            'id' => Str::uuid(),
            'user_id' => 1,
            'account_id' => $request->account_id,
            'symbol' => strtoupper($request->symbol),
            'side' => $request->side,
            'quantity' => $request->quantity,
            'entry_price' => $request->entry_price,
            'exit_price' => $request->exit_price,
            'fees' => $request->fees,
            'status' => $request->status,
            'opened_at' => $request->opened_at,
            'closed_at' => $request->closed_at,
            'notes' => $request->notes,
        ]);

        return response()->json(FinanceTransformer::trade($trade));
    }

    public function show(Trade $trade)
    {
        return response()->json(FinanceTransformer::trade($trade));
    }

    public function update(
        Request $request,
        Trade $trade
    ) {
        $trade->update([
            'account_id' => $request->account_id,
            'symbol' => strtoupper($request->symbol),
            'side' => $request->side,
            'quantity' => $request->quantity,
            'entry_price' => $request->entry_price,
            'exit_price' => $request->exit_price,
            'fees' => $request->fees,
            'status' => $request->status,
            'opened_at' => $request->opened_at,
            'closed_at' => $request->closed_at,
            'notes' => $request->notes,
        ]);

        return response()->json(
            FinanceTransformer::trade($trade->fresh())
        );
    }

    public function destroy(
        Trade $trade
    ) {
        $trade->delete();

        return response()->json([
            'success' => true
        ]);
    }
}