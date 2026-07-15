<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
class TaskController extends Controller
{
    // =========================
    // FORMAT TASK
    // =========================
    private function formatTask($task)
    {
        return [
            'id' => (string) $task->id,
            'boardId' =>
                (string) $task->board_id,
            'column' =>
                $task->column_key,
            'title' =>
                $task->title,
            'description' =>
                $task->description ?? '',
            'notes' =>
                $task->notes ?? '',
            'priority' =>
                $task->priority,
            'tags' =>
                $task->tags ?? [],
            'dueAt' =>
                $task->due_at
                    ? strtotime($task->due_at) * 1000
                    : null,
            'reminder' =>
                $task->reminder,
            'recurring' =>
                $task->recurring,
            'completedAt' =>
                $task->completed_at
                    ? strtotime($task->completed_at) * 1000
                    : null,
            'reminded' =>
                (bool) $task->reminded,
            'order' =>
                $task->position,
            'createdAt' =>
                strtotime($task->created_at) * 1000,
            // =========================
            // SUBTASKS
            // =========================
            'subtasks' =>
                $task->subtasks->map(function ($sub) {
                    return [
                        'id' =>
                            (string) $sub->id,
                        'title' =>
                            $sub->title,
                        'done' =>
                            (bool) $sub->done,
                    ];
                })->values(),
            // =========================
            // ATTACHMENTS
            // =========================
            'attachments' =>
                $task->attachments->map(function ($a) {
                    return [
                        'id' =>
                            (string) $a->id,
                        'name' =>
                            $a->name,
                        'size' =>
                            $a->size,
                        'dataUrl' =>
                            asset(
                                'storage/' .
                                $a->path
                            ),
                    ];
                })->values(),
            // =========================
            // ACTIVITY
            // =========================
            'activity' =>
                $task->activityLogs
                    ->map(function ($a) {
                        return [
                            'id' =>
                                (string) $a->id,
                            'ts' =>
                                $a->created_at
                                    ->timestamp * 1000,
                            'text' =>
                                $a->text,
                        ];
                    })
                    ->values(),
        ];
    }
    // =========================
    // LOG ACTIVITY
    // =========================
    private function logActivity(
        $taskId,
        $text
    ) {
        ActivityLog::create([
            'task_id' =>
                $taskId,
            'user_id' =>
                1,
            'text' =>
                $text,
        ]);
    }
    // =========================
    // GET ALL TASKS
    // =========================
    public function index()
    {
        $tasks =
            Task::with([
                'subtasks',
                'attachments',
                'activityLogs',
            ])
                ->orderBy('position')
                ->get()
                ->map(function ($task) {
                    return $this->formatTask(
                        $task
                    );
                });
        return response()->json(
            $tasks
        );
    }
    // =========================
    // SHOW TASK
    // =========================
    public function show($id)
    {
        $task =
            Task::with([
                'subtasks',
                'attachments',
                'activityLogs',
            ])->findOrFail($id);
        return response()->json(
            $this->formatTask(
                $task
            )
        );
    }
    // =========================
    // CREATE TASK
    // =========================
    public function store(Request $request)
    {
        $task = Task::create([
            'board_id' =>
                $request->board_id ?? 1,
            'user_id' =>
                $request->user_id ?? 1,
            'title' =>
                $request->title,
            'description' =>
                $request->description,
            'notes' =>
                $request->notes,
            'column_key' =>
                $request->column_key ?? 'todo',
            'priority' =>
                $request->priority ?? 'med',
            'tags' =>
                $request->tags ?? [],
            'due_at' =>
                $request->due_at
                    ? date(
                        'Y-m-d H:i:s',
                        strtotime(
                            $request->due_at
                        )
                    )
                    : null,
            'reminder' =>
                $request->reminder,
            'recurring' =>
                $request->recurring ?? 'none',
            'position' =>
                $request->position ?? 0,
            'reminded' =>
                false,
        ]);
        // =========================
        // ACTIVITY
        // =========================
        $this->logActivity(
            $task->id,
            'Task created'
        );
        \Log::info([
            'REMINDER_INCOMING' =>
                $request->reminder,
        ]);
        return response()->json([
            'success' => true,
            'task' =>
                $task,
        ]);
    }
// =========================
// UPDATE TASK
// =========================

public function update(
    Request $request,
    $id
) {

    $task =
        Task::findOrFail(
            $id
        );

    $oldColumn =
        $task->column_key;

    $oldPriority =
        $task->priority;

    $task->update([

        'title' =>
            $request->has('title')
                ? $request->title
                : $task->title,

        'description' =>
            $request->has('description')
                ? $request->description
                : $task->description,

        'notes' =>
            $request->has('notes')
                ? $request->notes
                : $task->notes,

        'column_key' =>
            $request->has('column_key')
                ? $request->column_key
                : $task->column_key,

        'priority' =>
            $request->has('priority')
                ? $request->priority
                : $task->priority,

        'tags' =>
            $request->has('tags')
                ? $request->tags
                : $task->tags,

        'due_at' =>
            $request->has('due_at')
                ? (
                    $request->due_at
                        ? date(
                            'Y-m-d H:i:s',
                            strtotime(
                                $request->due_at
                            )
                        )
                        : null
                )
                : $task->due_at,

        // =========================
        // FIX REMINDER
        // =========================

        'reminder' =>
            $request->has('reminder')
                ? $request->reminder
                : $task->reminder,

        'recurring' =>
            $request->has('recurring')
                ? $request->recurring
                : $task->recurring,

        'position' =>
            $request->has('position')
                ? $request->position
                : $task->position,

        'completed_at' =>
            $request->has('completed_at')
                ? (
                    $request->completed_at
                        ? date(
                            'Y-m-d H:i:s',
                            strtotime(
                                $request->completed_at
                            )
                        )
                        : null
                )
                : $task->completed_at,
    ]);

    // =========================
    // ACTIVITY
    // =========================

    $this->logActivity(
        $task->id,
        'Task updated'
    );

    if (
        $oldColumn !==
        $task->column_key
    ) {

        $this->logActivity(
            $task->id,
            'Moved to ' .
            $task->column_key
        );
    }

    if (
        $oldPriority !==
        $task->priority
    ) {

        $this->logActivity(
            $task->id,
            'Priority changed to ' .
            $task->priority
        );
    }

    \Log::info([

        'REMINDER_INCOMING' =>
            $request->reminder,

        'REMINDER_SAVED' =>
            $task->reminder,
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
        $task =
            Task::findOrFail(
                $id
            );
        $task->delete();
        return response()->json([
            'success' => true,
        ]);
    }

    // =========================
    // DELETE SUBTASK
    // =========================
    public function deleteSubtask($id)
    {
        // Cari subtask di dalam database, kalau ketemu langsung eksekusi mati
        // Gunakan query langsung ke DB atau Model Subtask lu (misal: \App\Models\Subtask)
        $subtask = \DB::table('subtasks')->where('id', $id)->first();
        
        if (!$subtask) {
            return response()->json([
                'success' => false,
                'message' => 'Subtask tidak ditemukan'
            ], 404);
        }

        \DB::table('subtasks')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subtask deleted successfully dari TaskController!'
        ]);
    }

    // =========================
// REORDER TASKS
// =========================

public function reorder(
    Request $request
) {

    $tasks =
        $request->tasks;

    if (!$tasks) {

        return response()->json([
            'success' => false,
        ]);
    }

    foreach ($tasks as $item) {

        Task::where(
            'id',
            $item['id']
        )->update([

            'position' =>
                $item['position'],
        ]);
    }

    return response()->json([

        'success' => true,
    ]);
}
}