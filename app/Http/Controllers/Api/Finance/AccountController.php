<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\FinanceTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function index()
    {
        $accounts = Account::orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Account $account) => FinanceTransformer::account($account));

        return response()->json($accounts);
    }

    public function store(Request $request)
    {
        $account = Account::create([
            'id' => Str::uuid(),
            'user_id' => 1,
            'name' => $request->name,
            'type' => $request->type,
            'balance' => $request->balance ?? 0,
            'opening_balance' => $request->balance ?? 0,
            'icon' => $request->icon ?? 'Wallet',
            'color' => $request->color,
            'notes' => $request->notes,
            'is_active' => true,
        ]);

        return response()->json(FinanceTransformer::account($account));
    }

    public function show(Account $account)
    {
        return response()->json(FinanceTransformer::account($account));
    }

    public function update(Request $request, Account $account)
    {
        $account->update([
            'name' => $request->name ?? $account->name,
            'type' => $request->type ?? $account->type,
            'icon' => $request->icon ?? $account->icon,
            'color' => $request->color ?? $account->color,
            'notes' => $request->notes,
        ]);

        return response()->json(FinanceTransformer::account($account->fresh()));
    }

    public function destroy(Account $account)
    {
        $account->delete();

        return response()->json([
            'success' => true
        ]);
    }
}