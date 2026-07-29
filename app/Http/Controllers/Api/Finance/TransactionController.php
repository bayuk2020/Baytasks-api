<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\FinanceTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct()
    {
        // Berikan kelonggaran khusus untuk method 'store' (saat import menembak bertubi-tok)
        // Batasan: 5000 request per 1 menit
        $this->middleware('throttle:5000,1')->only('store');
    }

    public function index(Request $request)
    {
        $transactions = Transaction::query()
            ->where(function ($query) {
                $query
                    ->whereNull('transfer_group_id')
                    ->orWhere('type', 'transfer');
            })
            ->orderByDesc('transaction_date')
            ->paginate(50);

        $transactions->getCollection()->transform(
            fn (Transaction $transaction) => FinanceTransformer::transaction($transaction)
        );

        return response()->json($transactions);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $transaction = DB::transaction(function () use ($validated) {
            if ($validated['type'] === 'transfer') {
                return $this->createTransfer($validated);
            }

            // KUNCI SAKTI: Ganti ::create jadi ::firstOrCreate
            // Parameter array pertama = kolom penentu keunikan (kalau ini sama, di-skip)
            // Parameter array kedua = data tambahan yang mau di-insert kalau emang belum ada
            $attributes = $this->transactionAttributes($validated);
            
            $transaction = Transaction::firstOrCreate(
                [
                    // Kalau ID, Akun, Tanggal, dan Jumlahnya udah ada yang kembar di DB, AUTO SKIP!
                    'id'               => $attributes['id'],
                    'account_id'       => $attributes['account_id'],
                    'transaction_date' => $attributes['transaction_date'],
                    'amount'           => $attributes['amount'],
                ],
                $attributes // Ini data sisanya (kategori, deskripsi, dll)
            );

            // Kondisi pelindung: Hanya update saldo bank JIKA transaksinya bener-bener baru dibuat!
            if ($transaction->wasRecentlyCreated) {
                $this->applyBalance($transaction);
            }

            return $transaction;
        });

        return response()->json(
            FinanceTransformer::transaction($transaction->fresh()),
            201
        );
    }

    public function show(Transaction $transaction)
    {
        return response()->json(
            FinanceTransformer::transaction($transaction)
        );
    }

    public function update(
        Request $request,
        Transaction $transaction
    ) {
        $validated = $this->validatePayload($request, $transaction->type);

        $updated = DB::transaction(function () use ($transaction, $validated) {
            if ($transaction->transfer_group_id) {
                return $this->updateTransfer($transaction, $validated);
            }

            $this->reverseBalance($transaction);
            $transaction->update($this->transactionAttributes($validated, false));
            $this->applyBalance($transaction);

            return $transaction;
        });

        return response()->json(
            FinanceTransformer::transaction($updated->fresh())
        );
    }

    public function destroy(Transaction $transaction)
    {
        DB::transaction(function () use ($transaction) {
            if ($transaction->transfer_group_id) {
                $transactions = Transaction::where(
                    'transfer_group_id',
                    $transaction->transfer_group_id
                )->get();

                foreach ($transactions as $entry) {
                    $this->reverseBalance($entry);
                    $entry->delete();
                }

                return;
            }

            $this->reverseBalance($transaction);
            $transaction->delete();
        });

        return response()->json(['success' => true]);
    }

    private function validatePayload(
        Request $request,
        ?string $requiredType = null
    ): array {
        return $request->validate([
            'id' => ['nullable', 'uuid'],
            'accountId' => ['required', 'uuid', 'exists:finance_accounts,id'],
            'toAccountId' => [
                'nullable',
                'required_if:type,transfer',
                'uuid',
                'different:accountId',
                'exists:finance_accounts,id',
            ],
            'type' => [
                'required',
                Rule::in($requiredType ? [$requiredType] : ['income', 'expense', 'transfer']),
            ],
            'category' => ['nullable', 'required_unless:type,transfer', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
            'transactionDate' => ['required', 'date'],
            'incomeSourceId' => [
                'nullable',
                'uuid',
                'exists:finance_income_sources,id',
            ],
            'contactId' => [
                'nullable',
                'uuid',
                'exists:finance_contacts,id',
            ],
        ]);
    }

    private function transactionAttributes(array $payload, bool $includeId = true): array 
    {
        $attributes = [
            'user_id'          => 1,
            'account_id'       => $payload['accountId'],
            'type'             => $payload['type'],
            'category'         => $payload['category'] ?? 'Transfer',
            'amount'           => $payload['amount'],
            'description'      => $payload['description'] ?? null,
            'transaction_date' => $payload['transactionDate'],
            'income_source_id' => $payload['type'] === 'income' ? ($payload['incomeSourceId'] ?? null) : null,
            'contact_id'       => $payload['contactId'] ?? null,
            'to_account_id'    => $payload['type'] === 'transfer' ? $payload['toAccountId'] : null,
        ];

        if ($includeId) {
            $attributes['id'] = $payload['id'] ?? (string) Str::uuid();
        }

        return $attributes;
    }

    private function createTransfer(array $payload): Transaction
    {
        $groupId = (string) Str::uuid();
        $outTransaction = Transaction::create([
            ...$this->transactionAttributes($payload),
            'category' => 'Transfer',
            'transfer_group_id' => $groupId,
        ]);
        $inTransaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'user_id' => 1,
            'account_id' => $payload['toAccountId'],
            'type' => 'income',
            'category' => 'Transfer In',
            'amount' => $payload['amount'],
            'description' => $payload['description'] ?? null,
            'transaction_date' => $payload['transactionDate'],
            'contact_id' => $payload['contactId'] ?? null,
            'transfer_group_id' => $groupId,
            'to_account_id' => $payload['accountId'],
        ]);

        $this->applyBalance($outTransaction);
        $this->applyBalance($inTransaction);

        return $outTransaction;
    }

    private function updateTransfer(
        Transaction $transaction,
        array $payload
    ): Transaction {
        $group = Transaction::where(
            'transfer_group_id',
            $transaction->transfer_group_id
        )->get();

        foreach ($group as $entry) {
            $this->reverseBalance($entry);
        }

        $outTransaction = $group->firstWhere('type', 'transfer') ?? $transaction;
        $inTransaction = $group->firstWhere('type', 'income');

        $outTransaction->update([
            ...$this->transactionAttributes($payload, false),
            'category' => 'Transfer',
        ]);

        if ($inTransaction) {
            $inTransaction->update([
                'account_id' => $payload['toAccountId'],
                'amount' => $payload['amount'],
                'description' => $payload['description'] ?? null,
                'transaction_date' => $payload['transactionDate'],
                'contact_id' => $payload['contactId'] ?? null,
                'to_account_id' => $payload['accountId'],
            ]);
        }

        $this->applyBalance($outTransaction);
        if ($inTransaction) {
            $this->applyBalance($inTransaction);
        }

        return $outTransaction;
    }

    private function applyBalance(Transaction $transaction): void
    {
        if ($transaction->type === 'income') {
            Account::where('id', $transaction->account_id)
                ->increment('balance', $transaction->amount);
        } elseif (in_array($transaction->type, ['expense', 'transfer'], true)) {
            Account::where('id', $transaction->account_id)
                ->decrement('balance', $transaction->amount);
        }
    }

    private function reverseBalance(Transaction $transaction): void
    {
        if ($transaction->type === 'income') {
            Account::where('id', $transaction->account_id)
                ->decrement('balance', $transaction->amount);
        } elseif (in_array($transaction->type, ['expense', 'transfer'], true)) {
            Account::where('id', $transaction->account_id)
                ->increment('balance', $transaction->amount);
        }
    }
}