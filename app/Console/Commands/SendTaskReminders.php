<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Task;

use App\Services\TelegramService;
use App\Models\TelegramSetting;
class SendTaskReminders extends Command
{
    protected $signature =
        'baytasks:reminders';

    protected $description =
        'Send Telegram task reminders';

    public function handle()
    {
        $telegram =
            new TelegramService();

        // =========================
        // TASKS
        // =========================

        $tasks =
            Task::whereNotNull(
                'due_at'
            )
            ->where(
                'reminded',
                false
            )
            ->get();

        dump([
            'TOTAL_TASKS' =>
                $tasks->count(),
        ]);

        foreach ($tasks as $task) {

            dump([
                'TASK' =>
                    $task->title,

                'REMINDER' =>
                    $task->reminder,

                'DUE_AT' =>
                    $task->due_at,
            ]);

            if (
                !$task->due_at
            ) {

                dump(
                    'SKIP: NO DUE DATE'
                );

                continue;
            }

            $due =
                strtotime(
                    $task->due_at
                );

            $now =
                time();

            $diff =
                $due - $now;

            dump([
                'DUE' =>
                    $due,

                'NOW' =>
                    $now,

                'DIFF_SECONDS' =>
                    $diff,
            ]);

            $send =
                false;

            // =========================
            // REMINDER
            // =========================

            if (
                $task->reminder ===
                    '10m' &&
                $diff <= 600 &&
                $diff >= -3600
            ) {

                dump(
                    'MATCH 10M'
                );

                $send = true;
            }

            if (
                $task->reminder ===
                    '1h' &&
                $diff <= 3600 &&
                $diff >= -300
            ) {

                dump(
                    'MATCH 1H'
                );

                $send = true;
            }

            if (
                $task->reminder ===
                    '1d' &&
                $diff <= 86400 &&
                $diff >= -300
            ) {

                dump(
                    'MATCH 1D'
                );

                $send = true;
            }

            if (!$send) {

                dump(
                    'SKIPPED'
                );

                continue;
            }

            // =========================
            // TELEGRAM MESSAGE
            // =========================

            $message =
                "⏰ BayTasks Reminder\n\n" .

                "Task:\n" .
                $task->title .
                "\n\n" .

                "Priority: " .
                strtoupper(
                    $task->priority
                ) .
                "\n\n" .

                "Due:\n" .
                date(
                    'd M Y H:i',
                    $due
                );

            // =========================
            // CHAT ID
            // =========================

            $setting =
                TelegramSetting::first();

            $chatId =
                $setting?->chat_id;

            dump([
                'CHAT_ID' =>
                    $chatId,
            ]);

            if (!$chatId) {

                dump(
                    'NO CHAT ID'
                );

                continue;
            }

            // =========================
            // SEND
            // =========================

            $response =
                $telegram->sendMessage(
                    $chatId,
                    $message
                );

            dump([
                'TELEGRAM_RESPONSE' =>
                    $response,
            ]);

            // =========================
            // UPDATE REMINDED
            // =========================

            $task->update([
                'reminded' =>
                    true,
            ]);

            $this->info(
                'Reminder sent: ' .
                $task->title
            );
        }

        return 0;
    }
}