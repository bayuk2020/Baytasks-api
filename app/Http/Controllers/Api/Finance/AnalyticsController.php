<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\Debt;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request)
    {
        // whereNull('transfer_group_id'): kaki "in" dari transfer disimpan dengan type='income'
        // supaya saldo akun tujuan bertambah, tapi ia BUKAN pemasukan riil — harus dikeluarkan
        // dari perhitungan income/cashflow, sama seperti TransactionController::index() men-filter listnya.
        $query = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->whereNull('transfer_group_id');

        // 1. Filter Tahun (SQL)
        if ($request->filled('tahun') && $request->tahun !== 'all') {
            $query->whereYear('transaction_date', $request->tahun);
        }
        
        // 2. Filter Bulan (SQL) - Kita handle string "01" atau angka murni dengan aman
        if ($request->filled('bulan') && $request->bulan !== 'all') {
            $bulanTarget = str_pad($request->bulan, 2, '0', STR_PAD_LEFT); // Pastikan selalu format "01", "02" s/d "12"
            $query->whereMonth('transaction_date', $bulanTarget);
        }
        
        if ($request->filled('tanggal')) {
            $query->whereDay('transaction_date', $request->tanggal);
        }
        if ($request->filled('account_id') && $request->account_id !== 'all') {
            $query->where('account_id', $request->account_id);
        }
        if ($request->filled('contact_id') && $request->contact_id !== 'all') {
            $query->where('contact_id', $request->contact_id);
        }

        $filteredTransactions = $query->orderByDesc('transaction_date')->get();

        // Hitung Rekap Angka Kartu Utama
        $income = $filteredTransactions->where('type', 'income')->sum('amount');
        $expense = $filteredTransactions->where('type', 'expense')->sum('amount');

        // Grouping Grafik Lingkaran (Source & Kategori)
        $bySource = $filteredTransactions->where('type', 'income')
            ->groupBy('category')
            ->map(fn($items, $cat) => ['name' => $cat, 'value' => (float) $items->sum('amount')])
            ->values();

        $byCategory = $filteredTransactions->where('type', 'expense')
            ->groupBy('category')
            ->map(fn($items, $cat) => ['name' => $cat, 'value' => (float) $items->sum('amount')])
            ->values()->sortByDesc('value')->values();

        // 3. KUNCI STRATEGI BARU: Ambil substring indeks bulan langsung dari text database!
        // Format dari database pastilah YYYY-MM-DD..., jadi karakter bulan itu ada di indeks ke 5 sepanjang 2 digit
        $trendStructure = [
            '01'=>'Jan', '02'=>'Feb', '03'=>'Mar', '04'=>'Apr', '05'=>'Mei', '06'=>'Jun',
            '07'=>'Jul', '08'=>'Agu', '09'=>'Sep', '10'=>'Okt', '11'=>'Nov', '12'=>'Des'
        ];
        
        $trend = [];
        foreach ($trendStructure as $num => $label) {
            $monthTx = $filteredTransactions->filter(function($t) use ($num) {
                // Ambil string mentah tanggalnya (apakah berupa objek Carbon, string date, atau timestamp)
                $rawDate = (string) ($t->transaction_date ?? $t->transactionDate);
                
                // Jika isinya string format standar database "2022-01-25..."
                if (strpos($rawDate, '-') !== false) {
                    $dateParts = explode('-', $rawDate);
                    return isset($dateParts[1]) ? str_pad($dateParts[1], 2, '0', STR_PAD_LEFT) === $num : false;
                }
                
                // Fallback aman jika tipenya ternyata timestamp angka milidetik (JS format bawaan store lu)
                if (is_numeric($rawDate) && strlen($rawDate) >= 10) {
                    $seconds = strlen($rawDate) === 13 ? (int)($rawDate / 1000) : (int)$rawDate;
                    return date('m', $seconds) === $num;
                }

                return false;
            });

            $inc = $monthTx->where('type', 'income')->sum('amount');
            $exp = $monthTx->where('type', 'expense')->sum('amount');
            
            $trend[] = [
                'month' => $label,
                'income' => (float) $inc,
                'expense' => (float) $exp,
                'net' => (float) ($inc - $exp)
            ];
        }

        // Hitung nilai aset statis global
        $netWorth = Account::sum('balance') - Debt::sum('remaining_debt');
        $totalCash = Account::where('type', '!=', 'trading')->sum('balance');
        $debtRemaining = Debt::sum('remaining_debt');

        return response()->json([
            'cards' => [
                'income' => (float) $income,
                'expense' => (float) $expense,
                'cashflow' => (float) ($income - $expense),
                'netWorth' => (float) $netWorth,
                'totalCash' => (float) $totalCash,
                'debtRemaining' => (float) $debtRemaining
            ],
            'charts' => [
                'bySource' => $bySource,
                'byCategory' => $byCategory,
                'trend' => $trend,
                'debt' => Debt::all()->map(fn($d) => [
                    'name' => $d->creditor,
                    'total' => (float) ($d->total_debt ?? $d->totalDebt),
                    'remaining' => (float) ($d->remaining_debt ?? $d->remainingDebt),
                    'paid' => (float) max(0, ($d->total_debt ?? $d->totalDebt) - ($d->remaining_debt ?? $d->remainingDebt))
                ])
            ]
        ]);
    }
}