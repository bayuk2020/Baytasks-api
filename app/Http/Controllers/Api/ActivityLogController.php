<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Timeline aktivitas Task/Subtask lintas SEMUA board, dipakai seksi
 * "Activity Log" di halaman Calendar (di bawah Daily Log/Memories).
 *
 * SENGAJA tidak memfilter task yang `hidden` -- hidden cuma menyembunyikan
 * task dari papan Kanban (biar tidak penuh sesak task selesai), riwayat
 * aktivitasnya di sini harus tetap utuh apa pun status hidden-nya.
 */
class ActivityLogController extends Controller
{
    private const RANGES = ['today', 'day', 'week', 'month', 'year', 'last_year'];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(self::RANGES)],
            'date' => ['nullable', 'date'],
        ]);

        $range = $validated['range'] ?? 'today';
        $anchor = isset($validated['date']) ? Carbon::parse($validated['date']) : now();

        [$from, $to] = $this->resolveRange($range, $anchor);

        $logs = ActivityLog::with('task:id,title,board_id,hidden')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'taskId' => $log->task_id,
                'taskTitle' => $log->task->title ?? '(task dihapus)',
                'taskHidden' => (bool) ($log->task->hidden ?? false),
                'text' => $log->text,
                'occurredAt' => $log->created_at?->timestamp * 1000,
            ])
            ->values();

        return response()->json([
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => $logs->count(),
            'logs' => $logs,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(string $range, Carbon $anchor): array
    {
        return match ($range) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'week' => [
                $anchor->copy()->startOfWeek(Carbon::SUNDAY),
                $anchor->copy()->endOfWeek(Carbon::SATURDAY),
            ],
            'month' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            'year' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            // "tahun kemarin" selalu relatif ke tahun SEKARANG (bukan ke
            // `date` yang dipilih) -- ini filter yang berdiri sendiri, bukan
            // turunan dari tanggal yang sedang dipilih di kalender.
            'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
        };
    }
}
