<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\ActivityLog;
use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    // =========================
    // FORMAT TASK
    // =========================
    private function formatTask($task)
    {
        return [
            'id' => (string) $task->id,
            'boardId' => (string) $task->board_id,
            'column' => $task->column_key,
            'title' => $task->title,
            'description' => $task->description ?? '',
            'notes' => $task->notes ?? '',
            'priority' => $task->priority,
            'tags' => $task->tags ?? [],
            'dueAt' => $task->due_at ? strtotime($task->due_at) * 1000 : null,
            'reminder' => $task->reminder,
            'recurring' => $task->recurring,
            'completedAt' => $task->completed_at ? strtotime($task->completed_at) * 1000 : null,
            'reminded' => (bool) $task->reminded,
            'hidden' => (bool) $task->hidden,
            'order' => $task->position,
            'createdAt' => strtotime($task->created_at) * 1000,

            // =========================
            // SUBTASKS
            // =========================
            'subtasks' => $task->subtasks->map(function ($sub) {
                return [
                    'id' => (string) $sub->id,
                    'title' => $sub->title,
                    'done' => (bool) $sub->done,
                    // FIX: Tambahan parse completedAt untuk subtask
                    'completedAt' => $sub->completed_at ? strtotime($sub->completed_at) * 1000 : null,
                ];
            })->values(),

            // =========================
            // ATTACHMENTS
            // =========================
            'attachments' => $task->attachments->map(function ($a) {
                return [
                    'id' => (string) $a->id,
                    'name' => $a->name,
                    'size' => $a->size,
                    'dataUrl' => asset('storage/' . $a->path),
                ];
            })->values(),

            // =========================
            // ACTIVITY
            // =========================
            'activity' => $task->activityLogs->map(function ($a) {
                return [
                    'id' => (string) $a->id,
                    'ts' => $a->created_at->timestamp * 1000,
                    'text' => $a->text,
                ];
            })->values(),
        ];
    }

    // =========================
    // LOG ACTIVITY
    // =========================
    private function logActivity($taskId, $text)
    {
        ActivityLog::create([
            'task_id' => $taskId,
            'user_id' => 1,
            'text' => $text,
        ]);
    }

    // =========================
    // LOG MEMORY (Daily Log di halaman Calendar)
    // =========================
    // Dipanggil dari SEMUA jalur perubahan task (drag Kanban, TaskModal, AI
    // via AiToolExecutor -- yang keduanya-duanya memanggil method di
    // controller ini) supaya "user menambahkan/mengedit/menyelesaikan tugas
    // X" tercatat satu tempat, tidak perlu ditulis ulang di tiap pemanggil.
    private function logMemory(string $title, string $content): void
    {
        Memory::create([
            'type' => 'task_activity',
            'source' => 'task_board',
            'title' => $title,
            'content' => $content,
            'occurred_at' => now(),
        ]);
    }

    // =========================
    // GET ALL TASKS
    // =========================
    public function index()
    {
        $tasks = Task::with(['subtasks', 'attachments', 'activityLogs'])
            ->orderBy('position')
            ->get()
            ->map(function ($task) {
                return $this->formatTask($task);
            });

        return response()->json($tasks);
    }

    // =========================
    // SHOW TASK
    // =========================
    public function show($id)
    {
        $task = Task::with(['subtasks', 'attachments', 'activityLogs'])->findOrFail($id);
        return response()->json($this->formatTask($task));
    }

    // =========================
    // CREATE TASK
    // =========================
    public function store(Request $request)
    {
        $task = Task::create([
            'board_id' => $request->board_id ?? 1,
            'user_id' => $request->user_id ?? 1,
            'title' => $request->title,
            'description' => $request->description,
            'notes' => $request->notes,
            'column_key' => $request->column_key ?? 'todo',
            'priority' => $request->priority ?? 'med',
            'tags' => $request->tags ?? [],
            'due_at' => $request->due_at ? date('Y-m-d H:i:s', strtotime($request->due_at)) : null,
            'reminder' => $request->reminder,
            'recurring' => $request->recurring ?? 'none',
            'position' => $request->position ?? 0,
            'reminded' => false,
        ]);

        $this->logActivity($task->id, 'Task created');
        $this->logMemory('Task ditambahkan', "User menambahkan tugas \"{$task->title}\"");
        Log::info(['REMINDER_INCOMING' => $request->reminder]);

        return response()->json([
            'success' => true,
            'task' => $task,
        ]);
    }

    // =========================
    // UPDATE TASK
    // =========================
    public function update(Request $request, $id)
    {
        $task = Task::findOrFail($id);
        $oldColumn = $task->column_key;
        $oldPriority = $task->priority;

        // Snapshot field lain SEBELUM update, dipakai untuk membedakan
        // "task diedit" vs "cuma direorder/di-drag ke kolom yang sama"
        // (kalau tidak ada satu pun field ini berubah, jangan tulis Memory --
        // supaya drag-reorder sehari-hari tidak membanjiri Daily Log).
        $oldTitle = $task->title;
        $oldDescription = $task->description;
        $oldDueAt = optional($task->due_at)->toIso8601String();
        $oldTags = $task->tags;

        // ==========================================================
        // completed_at DITENTUKAN OLEH KOLOM, bukan cuma oleh payload
        // ==========================================================
        // BUG LAMA: baris ini dulu hanya `$request->has('completed_at') ? ...`,
        // padahal store.ts SELALU mengirim `completed_at` -- bernilai null
        // kalau pemanggilnya tidak menyertakannya (lihat updateTask():
        // `patch.completedAt ? new Date(...) : null`). Akibatnya:
        //   - Menyelesaikan task lewat TaskModal (halaman /calendar maupun
        //     dropdown Status) mengubah kolom jadi "done" TAPI menulis
        //     completed_at = NULL, sehingga task itu tidak pernah terhitung
        //     di "Task Selesai" rekap Pomodoro, streak, & Weekly Momentum.
        //   - Sekadar mengedit judul task yang SUDAH selesai malah
        //     menghapus tanda selesainya.
        // Hanya drag di Kanban yang benar, karena ia satu-satunya pemanggil
        // yang mengirim timestamp secara eksplisit.
        //
        // Sekarang kolom (`column_key`) jadi sumber kebenaran: masuk "done"
        // berarti selesai, keluar dari "done" berarti batal selesai.
        $newColumn = $request->has('column_key') ? $request->column_key : $task->column_key;

        $explicitCompletedAt = $request->has('completed_at') && $request->completed_at
            ? date('Y-m-d H:i:s', strtotime($request->completed_at))
            : null;

        if ($newColumn === 'done') {
            // Prioritas: timestamp kiriman klien -> waktu selesai yang sudah
            // tercatat sebelumnya (jangan ditimpa saat task cuma diedit) -> sekarang.
            $resolvedCompletedAt = $explicitCompletedAt
                ?? optional($task->completed_at)->format('Y-m-d H:i:s')
                ?? now()->format('Y-m-d H:i:s');
        } else {
            $resolvedCompletedAt = null;
        }

        $task->update([
            'title' => $request->has('title') ? $request->title : $task->title,
            'description' => $request->has('description') ? $request->description : $task->description,
            'notes' => $request->has('notes') ? $request->notes : $task->notes,
            'column_key' => $request->has('column_key') ? $request->column_key : $task->column_key,
            'priority' => $request->has('priority') ? $request->priority : $task->priority,
            'tags' => $request->has('tags') ? $request->tags : $task->tags,
            'due_at' => $request->has('due_at') ? ($request->due_at ? date('Y-m-d H:i:s', strtotime($request->due_at)) : null) : $task->due_at,
            'reminder' => $request->has('reminder') ? $request->reminder : $task->reminder,
            'recurring' => $request->has('recurring') ? $request->recurring : $task->recurring,
            'position' => $request->has('position') ? $request->position : $task->position,
            'completed_at' => $resolvedCompletedAt,
            'hidden' => $request->has('hidden') ? filter_var($request->hidden, FILTER_VALIDATE_BOOLEAN) : $task->hidden,
        ]);

        $this->logActivity($task->id, 'Task updated');

        if ($oldColumn !== $task->column_key) {
            $this->logActivity($task->id, 'Moved to ' . $task->column_key);
        }

        if ($oldPriority !== $task->priority) {
            $this->logActivity($task->id, 'Priority changed to ' . $task->priority);
        }

        // BUKAN pakai completed_at sebagai penanda "baru saja selesai" --
        // field itu kadang ikut ter-null-kan oleh pemanggil yang tidak
        // menyertakannya (lihat store.ts:updateTask, selalu mengirim
        // completed_at:null kecuali eksplisit diisi). Kolom (column_key)
        // yang benar-benar berubah ke/dari "done" jauh lebih bisa diandalkan
        // karena itulah yang sungguh-sungguh digerakkan drag Kanban & dropdown
        // Status di TaskModal.
        $becameCompleted = $oldColumn !== 'done' && $task->column_key === 'done';

        $newDueAt = optional($task->due_at)->toIso8601String();
        $fieldsChanged = $oldTitle !== $task->title
            || $oldDescription !== $task->description
            || $oldDueAt !== $newDueAt
            || $oldPriority !== $task->priority
            || $oldTags !== $task->tags
            || ($oldColumn !== $task->column_key && $task->column_key !== 'done');

        if ($becameCompleted) {
            $this->logMemory('Task selesai', "User menyelesaikan tugas \"{$task->title}\"");
        } elseif ($fieldsChanged) {
            $this->logMemory('Task diedit', "User mengedit tugas \"{$task->title}\"");
        }

        Log::info([
            'REMINDER_INCOMING' => $request->reminder,
            'REMINDER_SAVED' => $task->reminder,
        ]);

        return response()->json([
            'success' => true,
            'task' => $task,
        ]);
    }

    // =========================
    // DELETE TASK
    // =========================
    public function destroy($id)
    {
        $task = Task::findOrFail($id);
        $task->delete();

        return response()->json(['success' => true]);
    }

    // =========================
    // DELETE SUBTASK
    // =========================
    public function deleteSubtask($id)
    {
        $subtask = DB::table('subtasks')->where('id', $id)->first();

        if (!$subtask) {
            return response()->json([
                'success' => false,
                'message' => 'Subtask tidak ditemukan'
            ], 404);
        }

        DB::table('subtasks')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subtask deleted successfully dari TaskController!'
        ]);
    }

    // =========================
    // REORDER TASKS
    // =========================
    public function reorder(Request $request)
    {
        $tasks = $request->tasks;

        if (!$tasks) {
            return response()->json(['success' => false]);
        }

        foreach ($tasks as $item) {
            Task::where('id', $item['id'])->update([
                'position' => $item['position'],
            ]);
        }

        return response()->json(['success' => true]);
    }
}
