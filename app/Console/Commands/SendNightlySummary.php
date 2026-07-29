<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Task;
use App\Models\Subtask;
use App\Models\TelegramSetting;
use App\Services\TelegramService;
use Carbon\Carbon;

class SendNightlySummary extends Command
{
    protected $signature = 'baytasks:nightly-summary';
    protected $description = 'Kirim Nightly Summary jam 21:00 WIB';

    public function handle()
    {
        $setting = TelegramSetting::first();
        if (!$setting || !$setting->chat_id) return 0;

        $today = Carbon::today('Asia/Jakarta');

        // 1. Data Habit
        $habits = Habit::where('archived', false)
            ->where('frequency', 'daily')
            ->orderBy('reminder_time', 'asc')
            ->get();

        // Cek log habit yang dikerjakan hari ini
        $doneHabitIds = HabitLog::whereDate('completed_at', $today)
            ->where('status', 'completed')
            ->pluck('habit_id')
            ->toArray();

        $habitDoneCount = 0;
        $totalHabit = $habits->count();
        $habitText = "";
        $streakText = "";

        foreach ($habits as $habit) {
            $isDone = in_array($habit->id, $doneHabitIds);
            if ($isDone) {
                $habitText .= "✅ {$habit->emoji} {$habit->title}\n";
                $habitDoneCount++;
            } else {
                $habitText .= "❌ {$habit->emoji} {$habit->title}\n";
            }

            // Render streak jika habit punya streak lebih dari 0
            if ($habit->current_streak > 0) {
                $streakText .= "{$habit->emoji} {$habit->title}\n{$habit->current_streak} Hari\n\n";
            }
        }

        $habitPercentage = $totalHabit > 0 ? round(($habitDoneCount / $totalHabit) * 100) : 0;

        // 2. Data Task Selesai Hari Ini
        $tasksDone = Task::whereDate('completed_at', $today)->get();

        // 3. Data Subtask Selesai Hari Ini
        $subtasksDone = Subtask::whereDate('completed_at', $today)->where('done', true)->get();

        // 4. Susun Pesan
        $msg = "🌙 <b>NIGHTLY SUMMARY</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Habit
        $msg .= "📈 <b>HABIT</b>\n";
        $msg .= $habitText . "\n";
        $msg .= "Progress\n";
        $msg .= "$habitDoneCount / $totalHabit\n";
        $msg .= "{$habitPercentage}%\n\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Task
        $msg .= "📌 <b>TASK SELESAI</b>\n\n";
        if ($tasksDone->isEmpty()) {
            $msg .= "<i>Tidak ada task yang diselesaikan hari ini.</i>\n";
        } else {
            foreach ($tasksDone as $task) {
                $msg .= "✅ {$task->title}\n";
            }
        }
        $msg .= "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Subtask
        $msg .= "📋 <b>SUBTASK SELESAI</b>\n\n";
        if ($subtasksDone->isEmpty()) {
            $msg .= "<i>Tidak ada subtask yang diselesaikan hari ini.</i>\n";
        } else {
            foreach ($subtasksDone as $subtask) {
                $msg .= "✅ {$subtask->title}\n";
            }
        }
        $msg .= "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Streak
        $msg .= "🔥 <b>CURRENT STREAK</b>\n\n";
        $msg .= $streakText ?: "<i>Belum ada streak yang menyala. Semangat!</i>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Ringkasan
        $msg .= "📊 <b>RINGKASAN</b>\n\n";
        $msg .= "Habit\n{$habitPercentage}%\n\n";
        $msg .= "Task\n{$tasksDone->count()}\n\n";
        $msg .= "Subtask\n{$subtasksDone->count()}\n\n";
        $msg .= "Selamat malam Bay, selamat istirahat! Sampai jumpa besok 🌙";

        // Kirim Telegram
        $telegram = new TelegramService();
        $telegram->sendMessage($setting->chat_id, $msg);

        $this->info('Nightly summary sent successfully!');
        return 0;
    }
}
