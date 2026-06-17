<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DebtController extends Controller
{
    public function index()
    {
        return response()->json(
            Debt::with('payments')
                ->latest()
                ->get()
        );
    }

    public function store(Request $request)
    {
        $debt = Debt::create([
            'id' => Str::uuid(),
            'creditor' => $request->creditor,
            'total_debt' => $request->total_debt,
            'remaining_debt' =>
                $request->remaining_debt
                ?? $request->total_debt,
            'monthly_payment' =>
                $request->monthly_payment,
            'due_date' =>
                $request->due_date,
            'notes' =>
                $request->notes,
            'status' => 'active',
        ]);

        return response()->json($debt);
    }

    public function show(Debt $debt)
    {
        return response()->json(
            $debt->load('payments')
        );
    }

    public function update(
        Request $request,
        Debt $debt
    ) {
        $debt->update([
            'creditor' =>
                $request->creditor,
            'total_debt' =>
                $request->total_debt,
            'remaining_debt' =>
                $request->remaining_debt,
            'monthly_payment' =>
                $request->monthly_payment,
            'due_date' =>
                $request->due_date,
            'notes' =>
                $request->notes,
            'status' =>
                $request->status
                ?? $debt->status,
        ]);

        return response()->json(
            $debt->fresh()
        );
    }

    public function destroy(
        Debt $debt
    ) {
        $debt->delete();

        return response()->json([
            'success' => true
        ]);
    }

    public function payment(
        Request $request,
        Debt $debt
    ) {
        DB::beginTransaction();

        try {

            $payment =
                DebtPayment::create([
                    'id' => Str::uuid(),
                    'debt_id' => $debt->id,
                    'account_id' =>
                        $request->account_id,
                    'amount' =>
                        $request->amount,
                    'paid_at' =>
                        $request->paid_at,
                    'notes' =>
                        $request->notes,
                ]);

            $remaining =
                max(
                    0,
                    $debt->remaining_debt
                    - $request->amount
                );

            $debt->update([
                'remaining_debt' =>
                    $remaining,
                'status' =>
                    $remaining <= 0
                    ? 'paid'
                    : 'active',
            ]);

            if ($request->account_id) {

                Account::where(
                    'id',
                    $request->account_id
                )->decrement(
                    'balance',
                    $request->amount
                );

                Transaction::create([
                    'id' => Str::uuid(),
                    'account_id' =>
                        $request->account_id,
                    'type' => 'expense',
                    'category' =>
                        'Debt Payment',
                    'amount' =>
                        $request->amount,
                    'description' =>
                        $request->notes
                        ?? 'Debt Payment',
                    'transaction_date' =>
                        date(
                            'Y-m-d',
                            strtotime(
                                $request->paid_at
                            )
                        ),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'payment' => $payment,
                'debt' => $debt->fresh(),
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' =>
                    $e->getMessage(),
            ], 500);
        }
    }
}