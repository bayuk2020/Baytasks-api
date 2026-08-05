<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Journal;
use App\Models\PomodoroSession;
use App\Models\Task;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Satu endpoint agregat untuk halaman Analytics: merangkum SEMUA modul
 * BayTasks (Tasks, Focus/Pomodoro, Habits, Finance, Journal, Reading, Goals)
 * dalam satu rentang tanggal.
 *
 * Sengaja dihitung di SQL/server, bukan di frontend, karena:
 *  - frontend cuma memuat sebagian data (tasks pun difilter per board aktif),
 *  - beberapa modul (transaksi 1000+ baris) kemahalan kalau ditarik semua
 *    ke browser hanya untuk dijumlahkan.
 */
class AnalyticsOverviewController extends Controller
{
    /** Target fokus harian (detik) -- dipakai bareng PomodoroController. */
    private const TARGET_FOCUS_SECONDS = 4 * 3600;

    public function __invoke(Request $request)
    {
        $days = min(max((int) $request->input('days', 30), 1), 365);

        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        // Deret tanggal lengkap supaya chart tidak bolong di hari tanpa aktivitas.
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = now()->subDays($i)->toDateString();
        }

        return response()->json([
            'range' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'daily' => $this->daily($dates, $from, $to),
            'tasks' => $this->tasks($from, $to),
            'focus' => $this->focus($from, $to, $days),
            'habits' => $this->habits($from, $to, $days),
            'finance' => $this->finance($from, $to),
            'journal' => $this->journal($from, $to),
            'reading' => $this->reading(),
            'goals' => $this->goals(),
        ]);
    }

    /**
     * Deret harian gabungan -- sumber data untuk semua chart garis/batang.
     */
    private function daily(array $dates, $from, $to): array
    {
        $created = Task::selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('d')->pluck('c', 'd');

        $completed = Task::selectRaw('DATE(completed_at) as d, COUNT(*) as c')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->groupBy('d')->pluck('c', 'd');

        $focus = PomodoroSession::selectRaw('DATE(started_at) as d, mode, SUM(duration_seconds) as total')
            ->whereNotNull('ended_at')
            ->whereBetween('started_at', [$from, $to])
            ->groupBy('d', 'mode')->get()
            ->groupBy('d');

        $habitDone = HabitLog::selectRaw('date as d, COUNT(*) as c')
            ->where('completed', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('d')->pluck('c', 'd');

        $journals = Journal::selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('d')->pluck('c', 'd');

        $money = Transaction::selectRaw('DATE(transaction_date) as d, type, SUM(amount) as total')
            ->whereIn('type', ['income', 'expense'])
            ->whereNull('transfer_group_id')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('d', 'type')->get()
            ->groupBy('d');

        $rows = [];
        foreach ($dates as $date) {
            $focusRow = $focus[$date] ?? collect();
            $moneyRow = $money[$date] ?? collect();

            $focusSec = (int) ($focusRow->firstWhere('mode', 'focus')->total ?? 0);
            $shortSec = (int) ($focusRow->firstWhere('mode', 'short')->total ?? 0);
            $longSec = (int) ($focusRow->firstWhere('mode', 'long')->total ?? 0);

            $tasksAdded = (int) ($created[$date] ?? 0);
            $tasksDone = (int) ($completed[$date] ?? 0);

            $rows[] = [
                'date' => $date,
                'tasksCreated' => $tasksAdded,
                'tasksCompleted' => $tasksDone,
                'focusSeconds' => $focusSec,
                'focusMinutes' => (int) round($focusSec / 60),
                'shortBreakSeconds' => $shortSec,
                'longBreakSeconds' => $longSec,
                'habitsCompleted' => (int) ($habitDone[$date] ?? 0),
                'journalEntries' => (int) ($journals[$date] ?? 0),
                'income' => (float) ($moneyRow->firstWhere('type', 'income')->total ?? 0),
                'expense' => (float) ($moneyRow->firstWhere('type', 'expense')->total ?? 0),
                'score' => $this->score($focusSec, $shortSec + $longSec, $tasksAdded, $tasksDone),
            ];
        }

        return $rows;
    }

    private function tasks($from, $to): array
    {
        $all = Task::query();

        $byPriority = (clone $all)->whereNull('completed_at')
            ->selectRaw('priority, COUNT(*) as c')->groupBy('priority')
            ->pluck('c', 'priority');

        $byColumn = (clone $all)
            ->selectRaw('column_key, COUNT(*) as c')->groupBy('column_key')
            ->pluck('c', 'column_key');

        $byBoard = Task::selectRaw('boards.name as board, COUNT(*) as total, SUM(CASE WHEN tasks.completed_at IS NOT NULL THEN 1 ELSE 0 END) as done')
            ->join('boards', 'boards.id', '=', 'tasks.board_id')
            ->groupBy('boards.name')
            ->get()
            ->map(fn ($r) => [
                'board' => $r->board,
                'total' => (int) $r->total,
                'done' => (int) $r->done,
            ]);

        $created = (clone $all)->whereBetween('created_at', [$from, $to])->count();
        $completed = (clone $all)->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])->count();

        $overdue = (clone $all)->whereNull('completed_at')
            ->whereNotNull('due_at')->where('due_at', '<', now())->count();

        // Rata-rata jeda dari dibuat sampai selesai (jam) -- indikator seberapa
        // lama task menggantung sebelum dibereskan.
        $avgHours = Task::whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_h')
            ->value('avg_h');

        return [
            'createdInRange' => $created,
            'completedInRange' => $completed,
            'openTotal' => (clone $all)->whereNull('completed_at')->count(),
            'overdue' => $overdue,
            // Pembaginya max(dibuat, selesai), BUKAN cuma `dibuat` -- kalau
            // dipakai `dibuat` saja, rentang yang menyelesaikan 4 task lama
            // tanpa membuat task baru akan tampil 0% padahal justru produktif.
            'completionRate' => max($created, $completed) > 0
                ? (int) round($completed / max($created, $completed) * 100)
                : 0,
            'avgCompletionHours' => $avgHours === null ? null : round((float) $avgHours, 1),
            'byPriority' => [
                'low' => (int) ($byPriority['low'] ?? 0),
                'med' => (int) ($byPriority['med'] ?? 0),
                'high' => (int) ($byPriority['high'] ?? 0),
                'urgent' => (int) ($byPriority['urgent'] ?? 0),
            ],
            'byColumn' => $byColumn->map(fn ($v) => (int) $v),
            'byBoard' => $byBoard,
        ];
    }

    private function focus($from, $to, int $days): array
    {
        $base = PomodoroSession::whereNotNull('ended_at')
            ->whereBetween('started_at', [$from, $to]);

        $totals = (clone $base)->selectRaw('mode, SUM(duration_seconds) as total, COUNT(*) as c')
            ->groupBy('mode')->get()->keyBy('mode');

        $focusSec = (int) ($totals['focus']->total ?? 0);
        $shortSec = (int) ($totals['short']->total ?? 0);
        $longSec = (int) ($totals['long']->total ?? 0);

        // Hari yang benar-benar ada aktivitas fokus -- dipakai untuk rata-rata
        // yang jujur (kalau dibagi seluruh hari, libur ikut menyeret turun).
        $activeDays = (clone $base)->where('mode', 'focus')
            ->selectRaw('COUNT(DISTINCT DATE(started_at)) as c')->value('c');

        $best = (clone $base)->where('mode', 'focus')
            ->selectRaw('DATE(started_at) as d, SUM(duration_seconds) as total')
            ->groupBy('d')->orderByDesc('total')->first();

        return [
            'focusSeconds' => $focusSec,
            'shortBreakSeconds' => $shortSec,
            'longBreakSeconds' => $longSec,
            'sessionCount' => (int) ($totals['focus']->c ?? 0),
            'activeDays' => (int) $activeDays,
            'avgPerActiveDaySeconds' => $activeDays > 0 ? (int) round($focusSec / $activeDays) : 0,
            'avgPerDaySeconds' => $days > 0 ? (int) round($focusSec / $days) : 0,
            'focusRatio' => ($focusSec + $shortSec + $longSec) > 0
                ? (int) round($focusSec / ($focusSec + $shortSec + $longSec) * 100)
                : 0,
            'bestDay' => $best ? ['date' => $best->d, 'seconds' => (int) $best->total] : null,
        ];
    }

    private function habits($from, $to, int $days): array
    {
        $active = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))->get();

        $logs = HabitLog::where('completed', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $perHabit = $logs->groupBy('habit_id');

        $breakdown = $active->map(function (Habit $h) use ($perHabit, $days) {
            // Pakai get() -- `$perHabit[$id] ?? 0` TIDAK aman di sini karena
            // `??` menempel pada hasil ->count(), sehingga akses arraynya
            // tetap dievaluasi duluan dan melempar error untuk habit yang
            // belum punya log sama sekali.
            $count = $perHabit->get($h->id)?->count() ?? 0;

            return [
                'title' => $h->title,
                'completed' => $count,
                // Konsistensi = berapa persen hari dalam rentang ini habit itu dikerjakan.
                'consistency' => $days > 0 ? (int) round($count / $days * 100) : 0,
            ];
        })->sortByDesc('completed')->values();

        return [
            'activeCount' => $active->count(),
            'completionsInRange' => $logs->count(),
            'possible' => $active->count() * $days,
            'overallConsistency' => ($active->count() * $days) > 0
                ? (int) round($logs->count() / ($active->count() * $days) * 100)
                : 0,
            'breakdown' => $breakdown,
        ];
    }

    private function finance($from, $to): array
    {
        $base = Transaction::whereNull('transfer_group_id')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

        $income = (float) (clone $base)->where('type', 'income')->sum('amount');
        $expense = (float) (clone $base)->where('type', 'expense')->sum('amount');

        $topExpense = (clone $base)->where('type', 'expense')
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')->orderByDesc('total')->limit(6)->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => (float) $r->total]);

        return [
            'income' => $income,
            'expense' => $expense,
            'cashflow' => $income - $expense,
            'transactionCount' => (clone $base)->count(),
            'savingRate' => $income > 0 ? (int) round(($income - $expense) / $income * 100) : 0,
            'topExpenseCategories' => $topExpense,
        ];
    }

    private function journal($from, $to): array
    {
        $entries = Journal::whereBetween('created_at', [$from, $to])->get();

        return [
            'entries' => $entries->count(),
            'byMood' => $entries->groupBy('mood')->map->count(),
        ];
    }

    private function reading(): array
    {
        $books = Book::all();

        return [
            'total' => $books->count(),
            'reading' => $books->where('status', 'reading')->count(),
            'completed' => $books->where('status', 'completed')->count(),
            'wishlist' => $books->where('status', 'wishlist')->count(),
            'pagesRead' => (int) $books->sum('current_page'),
        ];
    }

    private function goals(): array
    {
        $goals = Goal::all();

        return [
            'total' => $goals->count(),
            'completed' => $goals->where('completed', true)->count(),
            'avgProgress' => $goals->count() > 0
                ? (int) round($goals->avg('progress_percent'))
                : 0,
        ];
    }

    /** Rumus Score sama persis dengan PomodoroController supaya angkanya konsisten. */
    private function score(int $focus, int $breaks, int $tasksAdded, int $tasksCompleted): int
    {
        if ($focus === 0 && $breaks === 0 && $tasksAdded === 0 && $tasksCompleted === 0) {
            return 0;
        }

        $volume = min(100, $focus / self::TARGET_FOCUS_SECONDS * 100);
        $total = $focus + $breaks;
        $ratio = $total > 0 ? $focus / $total * 100 : 0;
        $task = min(100, $tasksCompleted / max($tasksAdded, 1) * 100);

        return (int) round(0.45 * $volume + 0.25 * $ratio + 0.30 * $task);
    }
}
