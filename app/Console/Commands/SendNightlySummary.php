<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Task;
use App\Models\Subtask;
use App\Models\TelegramSetting;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendNightlySummary extends Command
{
    protected $signature = 'baytasks:nightly-summary';
    protected $description = 'Kirim Nightly Summary jam 21:00 WIB';

    public function handle()
    {
        Log::info('[baytasks:nightly-summary] START');

        $setting = TelegramSetting::first();
        if (!$setting || !$setting->chat_id) {
            Log::warning('[baytasks:nightly-summary] ABORT: telegram_settings tidak ada / chat_id kosong');
            return 0;
        }

        if ($setting->is_sleeping) {
            Log::info('[baytasks:nightly-summary] SKIP: is_sleeping=true');
            return 0;
        }

        $today = Carbon::today('Asia/Jakarta');

        // 1. Data Habit
        // NULL di kolom `archived` harus dianggap belum diarsip -- lihat catatan
        // panjang soal bug ini di SendTaskReminders.php.
        $habits = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))
            ->where('frequency', 'daily')
            ->orderBy('reminder_time', 'asc')
            ->get();

        // BUG LAMA: tabel `habit_logs` tidak punya kolom `status` sama sekali
        // (kolomnya `completed` boolean) -- query ini sebelumnya SELALU melempar
        // SQL error "Unknown column 'status'" dan bikin seluruh command crash
        // sebelum sempat mengirim apa pun.
        $doneHabitIds = HabitLog::whereDate('completed_at', $today)
            ->where('completed', true)
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

            // Render streak jika habit punya streak lebih dari 0.
            // BUG LAMA: kolom modelnya `streak`, bukan `current_streak` (yang tidak
            // pernah ada) -- baris ini sebelumnya selalu null/diam-diam skip.
            if ($habit->streak > 0) {
                $streakText .= "{$habit->emoji} {$habit->title}\n{$habit->streak} Hari\n\n";
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

        Log::info('[baytasks:nightly-summary] DONE -- terkirim.', ['habit_done' => $habitDoneCount, 'habit_total' => $totalHabit]);
        $this->info('Nightly summary sent successfully!');
        return 0;
    }
}
