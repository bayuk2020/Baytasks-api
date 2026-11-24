<?php

namespace App\Services;

use App\Models\Task;

class RecurringTaskService
{
    public function run()
    {
        $tasks =
            Task::whereNotNull(
                'recurring'
            )
            ->where(
                'recurring',
                '!=',
                'none'
            )
            ->whereNotNull(
                'completed_at'
            )
            ->get();

        foreach (
            $tasks as $task
        ) {

            $exists =
                Task::where(
                    'title',
                    $task->title
                )
                ->whereDate(
                    'created_at',
                    now()->toDateString()
                )
                ->whereNull(
                    'completed_at'
                )
                ->exists();

            if ($exists)
                continue;

            $newDue =
                null;

            if (
                $task->due_at
            ) {

                $date =
                    \Carbon\Carbon::parse(
                        $task->due_at
                    );

                if (
                    $task->recurring ===
                    'daily'
                ) {

                    $newDue =
                        $date
                        ->copy()
                        ->addDay();
                }

                if (
                    $task->recurring ===
                    'weekly'
                ) {

                    $newDue =
                        $date
                        ->copy()
                        ->addWeek();
                }

                if (
                    $task->recurring ===
                    'monthly'
                ) {

                    $newDue =
                        $date
                        ->copy()
                        ->addMonth();
                }
            }

            Task::create([

                'board_id' =>
                    $task->board_id,

                // WAJIB diwarisi dari task asalnya. `tasks.user_id` NOT NULL
                // tanpa default dan punya foreign key ke `users.id`, jadi tanpa
                // baris ini MySQL menolak insert dengan:
                //   1364 Field 'user_id' doesn't have a default value
                // Akibatnya service ini gagal SETIAP MENIT sejak 27 Juni 2026:
                // task berulang tidak pernah terbuat, dan tiap kegagalan menulis
                // stacktrace ~4,5 KB ke laravel.log (20-25 MB per hari).
                // Fallback 1 mengikuti pola yang sudah dipakai di
                // TaskController@store (`$request->user_id ?? 1`) karena aplikasi
                // ini satu pengguna.
                'user_id' =>
                    $task->user_id ?? 1,

                'title' =>
                    $task->title,

                'description' =>
                    $task->description,

                'notes' =>
                    $task->notes,

                'column_key' =>
                    'todo',

                'priority' =>
                    $task->priority,

                'tags' =>
                    is_array($task->tags) ? $task->tags : json_decode($task->tags, true) ?? [],

                'due_at' =>
                    $newDue,

                'reminder' =>
                    $task->reminder,

                'recurring' =>
                    $task->recurring,

                'position' =>
                    0,

                'reminded' =>
                    false,
            ]);
        }
    }
}