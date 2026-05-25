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
                    $task->tags,

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