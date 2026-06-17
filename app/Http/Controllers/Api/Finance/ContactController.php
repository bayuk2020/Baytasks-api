<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreContactRequest;
use App\Http\Requests\Finance\UpdateContactRequest;
use App\Http\Resources\Finance\ContactResource;
use App\Models\Contact;
use App\Models\Transaction;
use App\Support\FinanceTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'type' => [
                'nullable',
                Rule::in(['person', 'family', 'employee', 'vendor', 'customer', 'other']),
            ],
        ]);

        $contacts = Contact::query()
            ->withCount('transactions')
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $query->where(function ($contactQuery) use ($search) {
                    $contactQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                $validated['type'] ?? null,
                fn ($query, string $type) => $query->where('type', $type)
            )
            ->orderBy('name')
            ->get();

        return ContactResource::collection($contacts);
    }

    public function store(StoreContactRequest $request)
    {
        $contact = Contact::create([
            'id' => (string) Str::uuid(),
            ...$request->validated(),
        ]);

        return (new ContactResource($contact))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Contact $contact)
    {
        $contact->loadCount('transactions');

        return new ContactResource($contact);
    }

    public function update(
        UpdateContactRequest $request,
        Contact $contact
    ) {
        $contact->update($request->validated());

        return new ContactResource($contact->fresh()->loadCount('transactions'));
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return response()->json(['success' => true]);
    }

    public function transactions(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['income', 'expense', 'transfer'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $transactions = $contact->transactions()
            ->where(function ($query) {
                $query
                    ->whereNull('transfer_group_id')
                    ->orWhere('type', 'transfer');
            })
            ->when(
                $validated['type'] ?? null,
                fn ($query, string $type) => $query->where('type', $type)
            )
            ->when(
                $validated['from'] ?? null,
                fn ($query, string $from) => $query->whereDate('transaction_date', '>=', $from)
            )
            ->when(
                $validated['to'] ?? null,
                fn ($query, string $to) => $query->whereDate('transaction_date', '<=', $to)
            )
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn (Transaction $transaction) => FinanceTransformer::transaction($transaction));

        return response()->json($transactions);
    }

    public function analytics(Request $request)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Transaction::query()
            ->whereNotNull('finance_transactions.contact_id')
            ->where(function ($query) {
                $query
                    ->whereNull('finance_transactions.transfer_group_id')
                    ->orWhere('finance_transactions.type', 'transfer');
            })
            ->when(
                $validated['from'] ?? null,
                fn ($query, string $from) => $query->whereDate(
                    'finance_transactions.transaction_date',
                    '>=',
                    $from
                )
            )
            ->when(
                $validated['to'] ?? null,
                fn ($query, string $to) => $query->whereDate(
                    'finance_transactions.transaction_date',
                    '<=',
                    $to
                )
            );

        $totals = (clone $base)
            ->join('finance_contacts', 'finance_contacts.id', '=', 'finance_transactions.contact_id')
            ->select([
                'finance_contacts.id',
                'finance_contacts.name',
                'finance_contacts.type',
                DB::raw("SUM(CASE WHEN finance_transactions.type = 'expense' THEN finance_transactions.amount ELSE 0 END) AS total_expense"),
                DB::raw("SUM(CASE WHEN finance_transactions.type = 'income' THEN finance_transactions.amount ELSE 0 END) AS total_income"),
                DB::raw("SUM(CASE WHEN finance_transactions.type = 'transfer' THEN finance_transactions.amount ELSE 0 END) AS total_transfer"),
                DB::raw('SUM(finance_transactions.amount) AS transaction_volume'),
                DB::raw('COUNT(*) AS transaction_count'),
            ])
            ->groupBy('finance_contacts.id', 'finance_contacts.name', 'finance_contacts.type')
            ->orderByDesc('transaction_volume')
            ->get()
            ->map(fn ($row) => [
                'contactId' => $row->id,
                'name' => $row->name,
                'type' => $row->type,
                'totalExpense' => (float) $row->total_expense,
                'totalIncome' => (float) $row->total_income,
                'totalTransfer' => (float) $row->total_transfer,
                'transactionVolume' => (float) $row->transaction_volume,
                'transactionCount' => (int) $row->transaction_count,
            ]);

        $familyTransfers = (clone $base)
            ->join('finance_contacts', 'finance_contacts.id', '=', 'finance_transactions.contact_id')
            ->where('finance_contacts.type', 'family')
            ->where('finance_transactions.type', 'transfer')
            ->sum('finance_transactions.amount');

        $employeeSalaries = (clone $base)
            ->join('finance_contacts', 'finance_contacts.id', '=', 'finance_transactions.contact_id')
            ->where('finance_contacts.type', 'employee')
            ->where('finance_transactions.type', 'expense')
            ->whereIn(DB::raw('LOWER(finance_transactions.category)'), [
                'salary',
                'gaji',
                'payroll',
            ])
            ->sum('finance_transactions.amount');

        return response()->json([
            'contacts' => $totals,
            'topContacts' => $totals->take($validated['limit'] ?? 10)->values(),
            'totalFamilyTransfers' => (float) $familyTransfers,
            'totalEmployeeSalaries' => (float) $employeeSalaries,
        ]);
    }
}
