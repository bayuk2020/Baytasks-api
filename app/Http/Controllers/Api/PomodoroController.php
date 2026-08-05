<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PomodoroSession;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PomodoroController extends Controller
{
    /**
     * Target fokus harian (detik) yang dianggap "hari yang penuh" saat
     * menghitung Score. 4 jam fokus bersih sudah termasuk hari yang sangat
     * produktif untuk kerja knowledge-work.
     */
    private const TARGET_FOCUS_SECONDS = 4 * 3600;

    /**
     * Segmen di bawah ambang ini tidak dicatat -- mencegah sampah data dari
     * klik Start-lalu-Pause yang tidak sengaja.
     */
    private const MIN_SEGMENT_SECONDS = 1;

    /**
     * Catat SATU segmen sesi yang baru saja selesai.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(PomodoroSession::MODES)],
            'startedAt' => ['required', 'date'],
            'endedAt' => ['required', 'date', 'after_or_equal:startedAt'],
            'completed' => ['nullable', 'boolean'],
        ]);

        $startedAt = Carbon::parse($validated['startedAt']);
        $endedAt = Carbon::parse($validated['endedAt']);

        // Durasi dihitung ULANG di server dari kedua timestamp -- jangan
        // percaya durasi kiriman klien supaya angkanya tidak bisa melenceng
        // (atau dikirim ngawur) dari rentang waktu yang sebenarnya.
        $duration = $endedAt->diffInSeconds($startedAt);

        if ($duration < self::MIN_SEGMENT_SECONDS) {
            return response()->json([
                'success' => false,
                'skipped' => true,
                'message' => 'Segmen terlalu pendek untuk dicatat.',
            ]);
        }

        $session = PomodoroSession::create([
            'user_id' => 1,
            'mode' => $validated['mode'],
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'completed' => (bool) ($validated['completed'] ?? false),
        ]);

        return response()->json([
            'success' => true,
            'session' => $this->transform($session),
        ], 201);
    }

    /**
     * Mulai sesi baru DI SERVER (sesi "terbuka": ended_at masih NULL).
     *
     * Ini yang membuat sesi bisa dimulai lewat chat AI / Telegram lalu
     * terlihat & bisa dihentikan dari web -- satu sumber kebenaran, bukan
     * timer terpisah di masing-masing tempat.
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(PomodoroSession::MODES)],
        ]);

        // Kalau masih ada sesi menggantung, tutup dulu supaya tidak menumpuk.
        $closed = $this->closeOpenSession();

        $session = PomodoroSession::create([
            'user_id' => 1,
            'mode' => $validated['mode'] ?? 'focus',
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => null,
            'completed' => false,
        ]);

        return response()->json([
            'success' => true,
            'session' => $this->transform($session),
            'closedPrevious' => $closed ? $this->transform($closed) : null,
        ], 201);
    }

    /**
     * Tutup sesi yang sedang berjalan dan hitung durasinya.
     */
    public function stop(Request $request)
    {
        $session = $this->closeOpenSession();

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada sesi Pomodoro yang sedang berjalan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session' => $this->transform($session),
        ]);
    }

    /**
     * Sesi yang sedang berjalan (atau null). Dipakai web untuk mengadopsi
     * sesi yang dimulai dari tempat lain.
     */
    public function active()
    {
        $session = $this->openSession();

        return response()->json([
            'active' => $session ? $this->transform($session) : null,
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    private function openSession(): ?PomodoroSession
    {
        return PomodoroSession::whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Tutup semua sesi terbuka (harusnya cuma satu; sisanya jaring pengaman
     * kalau sempat ada balapan request). Mengembalikan yang paling baru.
     */
    private function closeOpenSession(): ?PomodoroSession
    {
        $sessions = PomodoroSession::whereNull('ended_at')
            ->orderByDesc('started_at')
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        $now = now();
        $latest = null;

        foreach ($sessions as $session) {
            $session->ended_at = $now;
            $session->duration_seconds = max(0, $now->diffInSeconds($session->started_at));
            $session->save();

            $latest ??= $session;
        }

        return $latest;
    }

    /**
     * Total per mode untuk SATU hari (default hari ini). Dipakai kartu
     * "Focus time" di Dashboard.
     */
    public function today(Request $request)
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : now()->toDateString();

        $totals = $this->totalsForDates([$date])[$date] ?? [
            'focus' => 0, 'short' => 0, 'long' => 0,
        ];

        return response()->json([
            'date' => $date,
            'focusSeconds' => $totals['focus'],
            'shortBreakSeconds' => $totals['short'],
            'longBreakSeconds' => $totals['long'],
            'sessionCount' => PomodoroSession::whereDate('started_at', $date)
                ->whereNotNull('ended_at')
                ->count(),
        ]);
    }

    /**
     * Riwayat segmen mentah (dipakai daftar "sesi hari ini" di halaman Pomodoro).
     */
    public function index(Request $request)
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : now()->toDateString();

        $sessions = PomodoroSession::whereDate('started_at', $date)
            ->whereNotNull('ended_at')
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (PomodoroSession $s) => $this->transform($s));

        return response()->json(['date' => $date, 'sessions' => $sessions]);
    }

    /**
     * Tabel rekap harian: tanggal, task ditambahkan, task selesai, total
     * focus/short/long, dan Score 1-100.
     */
    public function stats(Request $request)
    {
        $days = min(max((int) $request->input('days', 14), 1), 90);

        // Rentang tanggal dibuat lengkap (termasuk hari kosong) supaya tabel
        // di frontend tidak "bolong" di hari tanpa aktivitas.
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = now()->subDays($i)->toDateString();
        }

        $from = $dates[0];
        $to = end($dates);

        $pomodoroTotals = $this->totalsForDates($dates);

        $tasksAdded = Task::selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->groupBy('d')
            ->pluck('c', 'd');

        $tasksCompleted = Task::selectRaw('DATE(completed_at) as d, COUNT(*) as c')
            ->whereNotNull('completed_at')
            ->whereBetween(DB::raw('DATE(completed_at)'), [$from, $to])
            ->groupBy('d')
            ->pluck('c', 'd');

        $rows = [];
        foreach ($dates as $date) {
            $focus = $pomodoroTotals[$date]['focus'] ?? 0;
            $short = $pomodoroTotals[$date]['short'] ?? 0;
            $long = $pomodoroTotals[$date]['long'] ?? 0;
            $added = (int) ($tasksAdded[$date] ?? 0);
            $done = (int) ($tasksCompleted[$date] ?? 0);

            $rows[] = [
                'date' => $date,
                'tasksAdded' => $added,
                'tasksCompleted' => $done,
                'focusSeconds' => $focus,
                'shortBreakSeconds' => $short,
                'longBreakSeconds' => $long,
                'score' => $this->score($focus, $short + $long, $added, $done),
            ];
        }

        // Terbaru di atas.
        return response()->json(['days' => $days, 'rows' => array_reverse($rows)]);
    }

    /**
     * Total detik per mode, dikelompokkan per tanggal.
     *
     * @param  string[]  $dates
     * @return array<string, array{focus: int, short: int, long: int}>
     */
    private function totalsForDates(array $dates): array
    {
        if (empty($dates)) {
            return [];
        }

        $rows = PomodoroSession::selectRaw('DATE(started_at) as d, mode, SUM(duration_seconds) as total')
            // Sesi yang MASIH BERJALAN belum punya durasi final -- jangan
            // ikut dijumlahkan, nanti dihitung setelah ditutup.
            ->whereNotNull('ended_at')
            ->whereBetween(DB::raw('DATE(started_at)'), [$dates[0], end($dates)])
            ->groupBy('d', 'mode')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->d] ??= ['focus' => 0, 'short' => 0, 'long' => 0];
            $out[$row->d][$row->mode] = (int) $row->total;
        }

        return $out;
    }

    /**
     * Score produktivitas harian 0-100, gabungan tiga sisi:
     *
     *   45%  VOLUME  -- seberapa banyak fokus dibanding target harian (4 jam).
     *   25%  RASIO   -- proporsi fokus dibanding total waktu (fokus+istirahat);
     *                   istirahat wajar tidak dihukum berat, tapi hari yang
     *                   isinya mayoritas istirahat akan turun.
     *   30%  TASK    -- rasio task selesai dibanding task yang masuk hari itu.
     *
     * Hari tanpa aktivitas apa pun bernilai 0.
     */
    private function score(int $focus, int $breaks, int $tasksAdded, int $tasksCompleted): int
    {
        if ($focus === 0 && $breaks === 0 && $tasksAdded === 0 && $tasksCompleted === 0) {
            return 0;
        }

        $volume = min(100, $focus / self::TARGET_FOCUS_SECONDS * 100);

        $totalTime = $focus + $breaks;
        $ratio = $totalTime > 0 ? $focus / $totalTime * 100 : 0;

        // Kalau tidak ada task masuk, pakai jumlah task selesai sebagai
        // pembilang atas basis 1 -- menyelesaikan task lama tetap dihargai.
        $taskBase = max($tasksAdded, 1);
        $task = min(100, $tasksCompleted / $taskBase * 100);

        return (int) round(0.45 * $volume + 0.25 * $ratio + 0.30 * $task);
    }

    private function transform(PomodoroSession $s): array
    {
        return [
            'id' => $s->id,
            'mode' => $s->mode,
            'startedAt' => $s->started_at?->toIso8601String(),
            'endedAt' => $s->ended_at?->toIso8601String(),
            'durationSeconds' => $s->duration_seconds === null ? null : (int) $s->duration_seconds,
            'completed' => (bool) $s->completed,
            'running' => $s->ended_at === null,
        ];
    }
}
