<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TradeController extends Controller
{
    public function index()
    {
        return response()->json(
            Trade::with('account')
                ->latest('opened_at')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $trade = Trade::create([
            'id' => Str::uuid(),
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

        return response()->json($trade);
    }

    public function show(Trade $trade)
    {
        return response()->json(
            $trade->load('account')
        );
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
            $trade->fresh()
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