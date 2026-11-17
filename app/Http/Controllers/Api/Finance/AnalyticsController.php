<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Debt;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    private const MONTH_LABELS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function __invoke(Request $request)
    {
        // Versi sebelumnya menarik SELURUH baris hasil filter ke memori lewat ->get(),
        // lalu menyapu collection itu 12 kali (sekali per bulan) untuk membangun tren,
        // plus groupBy terpisah untuk tiap grafik. Semua rekap itu sekarang dikerjakan
        // MySQL sebagai beberapa GROUP BY yang memakai index idx_txns_date — tidak ada
        // lagi baris transaksi yang dimuat ke PHP sama sekali.

        // Rekap kartu + grafik lingkaran: satu query, dipecah per type & category.
        $byTypeCategory = $this->filtered($request)
            ->selectRaw('type, category, SUM(amount) AS total')
            ->groupBy('type', 'category')
            ->get();

        $income = 0.0;
        $expense = 0.0;
        $bySource = [];
        $byCategory = [];

        foreach ($byTypeCategory as $row) {
            $total = (float) $row->total;

            if ($row->type === 'income') {
                $income += $total;
                $bySource[] = ['name' => $row->category, 'value' => $total];
            } else {
                $expense += $total;
                $byCategory[] = ['name' => $row->category, 'value' => $total];
            }
        }

        // byCategory tetap diurut dari pengeluaran terbesar, sama seperti versi lama.
        usort($byCategory, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        // Tren bulanan: MONTH() langsung di SQL, bukan parsing string tanggal di PHP.
        $monthlyRows = $this->filtered($request)
            ->selectRaw('MONTH(transaction_date) AS month_number, type, SUM(amount) AS total')
            ->groupBy('month_number', 'type')
            ->get();

        $monthly = [];
        foreach ($monthlyRows as $row) {
            $monthly[(int) $row->month_number][$row->type] = (float) $row->total;
        }

        $trend = [];
        foreach (self::MONTH_LABELS as $number => $label) {
            $monthIncome = $monthly[$number]['income'] ?? 0.0;
            $monthExpense = $monthly[$number]['expense'] ?? 0.0;

            $trend[] = [
                'month' => $label,
                'income' => $monthIncome,
                'expense' => $monthExpense,
                'net' => $monthIncome - $monthExpense,
            ];
        }

        // Nilai aset global: dua query agregat, bukan tiga kali SUM() terpisah.
        $accountTotals = Account::query()
            ->selectRaw('COALESCE(SUM(balance), 0) AS all_balance')
            ->selectRaw("COALESCE(SUM(CASE WHEN type <> 'trading' THEN balance ELSE 0 END), 0) AS cash_balance")
            ->first();

        $debtRows = Debt::query()
            ->select(['creditor', 'total_debt', 'remaining_debt'])
            ->get();

        $totalBalance = (float) ($accountTotals->all_balance ?? 0);
        $totalCash = (float) ($accountTotals->cash_balance ?? 0);
        $debtRemaining = (float) $debtRows->sum(fn (Debt $debt) => (float) $debt->remaining_debt);

        return response()->json([
            'cards' => [
                'income' => $income,
                'expense' => $expense,
                'cashflow' => $income - $expense,
                'netWorth' => $totalBalance - $debtRemaining,
                'totalCash' => $totalCash,
                'debtRemaining' => $debtRemaining,
            ],
            'charts' => [
                'bySource' => $bySource,
                'byCategory' => $byCategory,
                'trend' => $trend,
                'debt' => $debtRows->map(fn (Debt $debt) => [
                    'name' => $debt->creditor,
                    'total' => (float) $debt->total_debt,
                    'remaining' => (float) $debt->remaining_debt,
                    'paid' => max(0.0, (float) $debt->total_debt - (float) $debt->remaining_debt),
                ])->values(),
            ],
        ]);
    }

    /**
     * Query dasar + filter dari request. Dipanggil sekali per agregat supaya tiap
     * query punya builder bersih (builder Eloquent bersifat mutable).
     *
     * whereNull('transfer_group_id'): kaki "in" dari transfer disimpan dengan
     * type='income' supaya saldo akun tujuan bertambah, tapi ia BUKAN pemasukan
     * riil — harus dikeluarkan dari perhitungan income/cashflow, sama seperti
     * TransactionController::index() men-filter listnya.
     */
    private function filtered(Request $request): Builder
    {
        $query = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->whereNull('transfer_group_id');

        if ($request->filled('tahun') && $request->input('tahun') !== 'all') {
            $query->whereYear('transaction_date', $request->input('tahun'));
        }

        if ($request->filled('bulan') && $request->input('bulan') !== 'all') {
            $query->whereMonth('transaction_date', (int) $request->input('bulan'));
        }

        if ($request->filled('tanggal')) {
            $query->whereDay('transaction_date', (int) $request->input('tanggal'));
        }

        if ($request->filled('account_id') && $request->input('account_id') !== 'all') {
            $query->where('account_id', $request->input('account_id'));
        }

        if ($request->filled('contact_id') && $request->input('contact_id') !== 'all') {
            $query->where('contact_id', $request->input('contact_id'));
        }

        return $query;
    }
}
