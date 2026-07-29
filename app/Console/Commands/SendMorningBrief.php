<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Habit;
use App\Models\Task;
use App\Models\TelegramSetting;
use App\Services\TelegramService;
use Carbon\Carbon;

class SendMorningBrief extends Command
{
    protected $signature = 'baytasks:morning-brief';
    protected $description = 'Kirim Morning Quest Log jam 07:00 WIB';

    public function handle()
    {
        $setting = TelegramSetting::first();
        if (!$setting || !$setting->chat_id) return 0;

        // 1. Ambil Data Habit
        $habits = Habit::where('archived', false)
            ->where('frequency', 'daily')
            ->orderBy('reminder_time', 'asc')
            ->get();

        // 2. Ambil Data Task & Subtask
        // Catatan: Sorting priority diubah jadi raw agar urut (high > med > low)
        $tasks = Task::with(['subtasks' => function ($q) {
            $q->where('done', false)->orderBy('position', 'asc');
        }])
            ->whereNull('completed_at')
            ->orderByRaw("FIELD(priority, 'high', 'med', 'low')")
            ->orderBy('due_at', 'asc')
            ->orderBy('position', 'asc')
            ->get();

        // 3. Kalkulasi Target
        $targetHabit = $habits->count();
        $targetTask = $tasks->count();
        $targetSubtask = 0;

        // 4. Susun Pesan
        $msg = "🌅 <b>MORNING QUEST LOG</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $msg .= "Semangat pagi! ☀️\n";
        $msg .= "Hari ini adalah kesempatan baru untuk menambah XP dan menjaga streak.\n\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Habit
        $msg .= "🎯 <b>MAIN QUEST (Habit)</b>\n";
        if ($targetHabit == 0) $msg .= "<i>Tidak ada habit hari ini.</i>\n";
        foreach ($habits as $habit) {
            $msg .= "☐ {$habit->emoji} {$habit->title}\n";
        }
        $msg .= "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Task & Subtask
        $msg .= "📌 <b>TASK</b>\n\n";
        if ($targetTask == 0) $msg .= "<i>Tidak ada task tersisa. Kamu bebas!</i>\n";

        foreach ($tasks as $task) {
            $msg .= "☐ {$task->title}\n";
            foreach ($task->subtasks as $subtask) {
                $msg .= "     ☐ {$subtask->title}\n";
                $targetSubtask++; // Hitung total subtask
            }
        }
        $msg .= "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Target
        $msg .= "🎁 <b>TARGET HARI INI</b>\n\n";
        $msg .= "Habit : $targetHabit\n";
        $msg .= "Task : $targetTask\n";
        $msg .= "Subtask : $targetSubtask\n\n";
        $msg .= "Semoga harimu produktif! 🚀";

        // Kirim Telegram
        $telegram = new TelegramService();
        $telegram->sendMessage($setting->chat_id, $msg);

        $this->info('Morning brief sent successfully!');
        return 0;
    }
}
