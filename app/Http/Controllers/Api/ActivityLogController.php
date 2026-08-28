<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Daftar TASK untuk seksi "Activity Log" di halaman Calendar.
 *
 * CATATAN PENTING soal penamaan: ini BUKAN pembacaan tabel `activity_logs`
 * (audit trail "Task created" / "Moved to done"). Yang dimaksud "Activity Log"
 * di sini adalah daftar task yang pernah dibuat user -- kapan dibuatnya, kapan
 * deadline-nya, sudah selesai atau belum -- lintas SEMUA board.
 *
 * Dua hal yang sengaja TIDAK difilter di sini:
 *  - `hidden`: kolom itu cuma menyembunyikan task dari papan Kanban. Di daftar
 *    ini task tersembunyi tetap ditampilkan (ditandai badge di UI), supaya
 *    riwayat pekerjaan tetap utuh.
 *  - board: selalu lintas semua board, dan nama board-nya ikut dikirim
 *    supaya bisa dilabeli di tiap baris.
 */
class ActivityLogController extends Controller
{
    private const STATUSES = ['backlog', 'todo', 'in_progress', 'review', 'done'];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Dikirim sebagai daftar dipisah koma, mis. "todo,in_progress".
            'status' => ['nullable', 'string'],
        ]);

        // Default: bulan berjalan. Rentang dibatasi ke created_at karena
        // pertanyaan yang dijawab tabel ini adalah "task apa saja yang aku
        // BUAT pada rentang ini" (lihat pilihan filter di UI).
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfMonth();

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfMonth();

        // Rentang terbalik (user isi "dari" lebih besar dari "sampai") tidak
        // dianggap error -- cukup ditukar supaya hasilnya tetap masuk akal.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $statuses = collect(explode(',', $validated['status'] ?? ''))
            ->map(fn ($s) => trim($s))
            ->filter(fn ($s) => in_array($s, self::STATUSES, true))
            ->values();

        // `title` ikut diambil karena laporan PDF/Excel mencantumkan tiap
        // subtask beserta status selesainya, bukan cuma jumlahnya.
        // `attachments` dipakai kolom Attachment (nama file + tautannya).
        $query = Task::with([
            'subtasks:id,task_id,title,done,position',
            'attachments:id,task_id,name,size,path',
        ])
            ->leftJoin('boards', 'boards.id', '=', 'tasks.board_id')
            ->whereBetween('tasks.created_at', [$from, $to])
            ->select('tasks.*', 'boards.name as board_name', 'boards.emoji as board_emoji');

        if ($statuses->isNotEmpty()) {
            $query->whereIn('tasks.column_key', $statuses->all());
        }

        $tasks = $query->orderByDesc('tasks.created_at')
            ->limit(500)
            ->get()
            ->map(function (Task $task) {
                $subtaskTotal = $task->subtasks->count();
                $subtaskDone = $task->subtasks->where('done', true)->count();

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'notes' => $task->notes,
                    'boardId' => $task->board_id,
                    'boardName' => $task->board_name ?? '(tanpa board)',
                    'boardEmoji' => $task->board_emoji,
                    'column' => $task->column_key,
                    'priority' => $task->priority,
                    'hidden' => (bool) $task->hidden,
                    // JANGAN pakai `$x?->timestamp * 1000`: operator `?->`
                    // memang menghasilkan null, tapi perkalian setelahnya
                    // mengubah null itu jadi 0 -- akibatnya task tanpa
                    // deadline/belum selesai terkirim sebagai epoch 0
                    // (1 Januari 1970) alih-alih null.
                    'createdAt' => $task->created_at ? $task->created_at->timestamp * 1000 : null,
                    'dueAt' => $task->due_at ? $task->due_at->timestamp * 1000 : null,
                    'completedAt' => $task->completed_at ? $task->completed_at->timestamp * 1000 : null,
                    'subtaskTotal' => $subtaskTotal,
                    'subtaskDone' => $subtaskDone,
                    'subtasks' => $task->subtasks->map(fn ($s) => [
                        'id' => $s->id,
                        'title' => $s->title,
                        'done' => (bool) $s->done,
                    ])->values(),
                    // URL dibangun sama seperti TaskController::formatTask()
                    // supaya tautan di laporan identik dengan yang dipakai UI.
                    'attachments' => $task->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'url' => asset('storage/'.$a->path),
                    ])->values(),
                ];
            });

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'statuses' => $statuses->all(),
            'count' => $tasks->count(),
            'tasks' => $tasks,
        ]);
    }
}
