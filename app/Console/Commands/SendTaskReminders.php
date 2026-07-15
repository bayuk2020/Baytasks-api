<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Services\TelegramService;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\DB;

class SendTaskReminders extends Command
{
    protected $signature = 'baytasks:reminders';
    protected $description = 'Send Telegram task reminders and hourly pulse activity checkers';

    public function handle()
    {
        $telegram = new TelegramService();

        // =========================================================
        // AMBIL DATA CHAT ID UTAMA
        // =========================================================
        $setting = TelegramSetting::first();
        if (!$setting || !$setting->enabled || !$setting->chat_id) {
            dump('ERROR: NO TELEGRAM CHAT ID OR INTEGRATION DISABLED');
            return 1;
        }

        $chatId = $setting->chat_id;

        // =========================================================
        // PENGKONDISIAN A: TRIGGER INTERUPSI AKTIVITAS TIAP 15 MENIT (PULSE CHECKER)
        // =========================================================
        // FIX: Hanya eksekusi pada menit kelipatan 15 (00, 15, 30, 45) agar tidak spam tiap menit
        // if ((int)date('i') % 15 === 0) {
        if (true) {
        // 
        
            // Ambil data session saat ini di database
            $currentSession = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();
            
            // SUNTIK STATUS PROTEKSI BIAR GAK DI-SPAM PAS MILIH / EDIT TUGAS ATAU SEDANG SANTAI
            if ($currentSession && (
                str_starts_with($currentSession->step, 'form_') || 
                str_contains($currentSession->step, 'leisure') || // Tambahan proteksi dashboard santai kawan!
                $currentSession->step === 'waiting_destination' || 
                $currentSession->step === 'waiting_task_selection' || 
                $currentSession->step === 'waiting_subtask' ||
                $currentSession->step === 'waiting_subtask_confirmation' ||
                $currentSession->step === 'task_wizard_running' // Tambahan proteksi wizard
            )) {
                dump("SKIP 15-MIN PULSE: Bayu is currently busy handling a module ({$currentSession->step}).");
            } else {
                dump("MATCH: 15-MINUTE ACTIVITY PULSE CHECK TRIGGERED");
                
                $pulseMenu = [
                    'inline_keyboard' => [
                        [['text' => '💻 Kerja', 'callback_data' => 'pulse_kerja'], ['text' => '📖 Belajar', 'callback_data' => 'pulse_belajar']],
                        [['text' => '☕ Santai', 'callback_data' => 'pulse_santai'], ['text' => '🌐 Lainnya', 'callback_data' => 'pulse_lainnya']]
                    ]
                ];

                $telegram->sendMessage($chatId, "🔔 <b>Halo Bayu, saat ini Anda sedang melakukan aktivitas apa?</b>", [
                    'reply_markup' => json_encode($pulseMenu)
                ]);

                // Hanya reset ke idle jika posisi urusan user benar-benar luang
                DB::table('telegram_sessions')->updateOrInsert(
                    ['chat_id' => $chatId],
                    ['step' => 'idle', 'active_task_id' => null, 'updated_at' => now()]
                );
            }
        }

        // =========================================================
        // PENGKONDISIAN B: JALUR REMINDER JATUH TEMPO TASK
        // =========================================================
        $tasks = Task::whereNull('completed_at')
            ->where('reminded', false)
            ->whereNotNull('due_at')
            ->get();

        $now = time();

        foreach ($tasks as $task) {
            $due = strtotime($task->due_at);
            $diff = $due - $now;

            $send = false;
            $statusText = "";

            if ($task->reminder === '10m' && $diff <= 600 && $diff > 60) {
                dump("MATCH: H-10 MINUTES FOR TASK: " . $task->title);
                $statusText = "⏰ <b>Pengingat Aktivitas (10 Menit Lagi)</b>";
                $send = true;
            }

            if (($task->reminder === '0m' || $task->reminder === '10m') && $diff <= 60 && $diff >= -60) {
                dump("MATCH: EXACT TIME FOR TASK: " . $task->title);
                $statusText = "🚨 <b>WAKTUNYA SEKARANG!</b>";
                $send = true;
            }

            if ($task->reminder === '1h' && $diff <= 3600 && $diff >= -300) {
                $statusText = "⏳ <b>Pengingat Aktivitas (1 Jam Lagi)</b>";
                $send = true;
            }
            if ($task->reminder === '1d' && $diff <= 86400 && $diff >= -300) {
                $statusText = "📅 <b>Pengingat Aktivitas (1 Hari Lagi)</b>";
                $send = true;
            }

            if (!$send) {
                continue;
            }

            $message = "{$statusText}\n\n" .
                       "• <b>Tugas:</b> {$task->title}\n" .
                       "• <b>Prioritas:</b> " . strtoupper($task->priority) . "\n" .
                       "• <b>Jadwal:</b> " . date('d M Y H:i', $due) . " WIB\n\n" .
                       "Apakah tugas ini sudah selesai Anda kerjakan?";

            $replyMarkup = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Sudah', 'callback_data' => 'task_done_' . $task->id],
                        ['text' => '❌ Belum', 'callback_data' => 'task_notdone_' . $task->id]
                    ]
                ]
            ];

            $telegram->sendMessage($chatId, $message, [
                'reply_markup' => json_encode($replyMarkup)
            ]);

            if ($statusText === "🚨 <b>WAKTUNYA SEKARANG!</b>" || $task->reminder !== '10m') {
                $task->update(['reminded' => true]);
            } else {
                $task->update(['reminder' => '0m']);
            }
        }

        return 0;
    }
}