<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\Habit;
use App\Services\TelegramService;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache; // Wajib tambah ini buat fitur Gembok
use Carbon\Carbon;

class SendTaskReminders extends Command
{
    protected $signature = 'baytasks:reminders';
    protected $description = 'Send Telegram task reminders, complex habits engine, and hourly pulse activity checkers';

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

        // BINDING TIMESTAMP UTAMA (UNTUK METODE TOLERANSI RENTANG DETIK)
        $now = time();
        $todayStr = Carbon::now('Asia/Jakarta')->toDateString();

        // =========================================================
        // PENGKONDISIAN A: TRIGGER INTERUPSI AKTIVITAS TIAP 15 MENIT (PULSE CHECKER)
        // =========================================================
        if ((int)date('i') % 15 === 0) {

            // Kunci unik berdasarkan tahun, bulan, tanggal, jam, dan menit (misal: pulse_sent_202607210830)
            $pulseKey = "pulse_sent_" . date('YmdHi');

            // Cek apakah di menit ini notifikasi pulse sudah pernah dikirim
            if (!Cache::has($pulseKey)) {

                // Pasang gembok cache selama 2 menit (120 detik) agar tidak double send pada loop 30 detik berikutnya
                Cache::put($pulseKey, true, 120);

                $currentSession = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();

                if ($currentSession && (
                    str_starts_with($currentSession->step, 'form_') ||
                    str_contains($currentSession->step, 'leisure') ||
                    $currentSession->step === 'waiting_destination' ||
                    $currentSession->step === 'waiting_task_selection' ||
                    $currentSession->step === 'waiting_subtask' ||
                    $currentSession->step === 'waiting_subtask_confirmation' ||
                    $currentSession->step === 'task_wizard_running'
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

                    DB::table('telegram_sessions')->updateOrInsert(
                        ['chat_id' => $chatId],
                        ['step' => 'idle', 'active_task_id' => null, 'updated_at' => now()]
                    );
                }
            }
        }

        // =========================================================
        // PENGKONDISIAN B: JALUR REMINDER JATUH TEMPO TASK
        // =========================================================
        $tasks = Task::whereNull('completed_at')
            ->where('reminded', false)
            ->whereNotNull('due_at')
            ->get();

        foreach ($tasks as $task) {
            $due = strtotime($task->due_at);
            $diff = $due - $now;

            $send = false;
            $statusText = "";
            $cacheKey = "";

            // Cek Fase Waktu & Buat Kunci Gembok Unik
            if ($task->reminder === '10m' && $diff <= 600 && $diff > 60) {
                $statusText = "⏰ <b>Pengingat Aktivitas (10 Menit Lagi)</b>";
                $cacheKey = "task_10m_{$task->id}";
                $send = true;
            } elseif (($task->reminder === '0m' || $task->reminder === '10m') && $diff <= 60 && $diff >= -60) {
                $statusText = "🚨 <b>WAKTUNYA SEKARANG!</b>";
                $cacheKey = "task_0m_{$task->id}";
                $send = true;
            } elseif ($task->reminder === '1h' && $diff <= 3600 && $diff > 600) {
                $statusText = "⏳ <b>Pengingat Aktivitas (1 Jam Lagi)</b>";
                $cacheKey = "task_1h_{$task->id}";
                $send = true;
            } elseif ($task->reminder === '1d' && $diff <= 86400 && $diff > 3600) {
                $statusText = "📅 <b>Pengingat Aktivitas (1 Hari Lagi)</b>";
                $cacheKey = "task_1d_{$task->id}";
                $send = true;
            }

            if (!$send) {
                continue;
            }

            // =========================================================
            // 🔥 GEMBOK CACHE BIAR GAK SPAM!
            // =========================================================
            if (Cache::has($cacheKey)) {
                continue; // Kalau sudah dikirim, langsung skip loop-nya
            }

            // Kunci selama 24 jam penuh (86400 detik)
            Cache::put($cacheKey, true, 86400);

            // =========================================================

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

            // Hanya tandai reminded = true di database jika ini adalah alarm terakhir
            if ($statusText === "🚨 <b>WAKTUNYA SEKARANG!</b>") {
                $task->update(['reminded' => true]);
            }
        }

        // =========================================================
        // PENGKONDISIAN C: JALUR REMINDER & EVALUASI HABIT DENGAN GEMBOK CACHE
        // =========================================================

        // 1. EVALUASI HABIT YANG TERLEWAT
        $expiredHabits = Habit::where('archived', false)
            ->whereNotNull('due_time')
            ->whereNotExists(function ($query) use ($todayStr) {
                $query->select(DB::raw(1))
                    ->from('habit_logs')
                    ->whereRaw('habit_logs.habit_id = habits.id')
                    ->where('habit_logs.date', $todayStr);
            })->get();

        foreach ($expiredHabits as $exHabit) {
            $dueTimestamp = strtotime($todayStr . ' ' . $exHabit->due_time);
            $diff = $dueTimestamp - $now;

            if ($diff <= 0 && $diff >= -60) {
                $totalSnoozeCount = DB::table('memories')
                    ->where('type', 'habit_snooze_log')
                    ->where('title', 'like', "%Habit ID: {$exHabit->id}%")
                    ->whereDate('created_at', $todayStr)->count();

                DB::table('habit_logs')->insert([
                    'habit_id'     => $exHabit->id,
                    'date'         => $todayStr,
                    'completed'    => false,
                    'completed_at' => null,
                    'notes'        => "Habit terlewat, user menunda habit selama {$totalSnoozeCount} kali.",
                    'created_at'   => now(),
                    'updated_at'   => now()
                ]);

                $exHabit->update(['snooze_until' => null, 'streak' => 0]);
                $telegram->sendMessage($chatId, "❌ <b>Habit Terlewat!</b>\nWaduh Bay, batas akhir pengerjaan <b>{$exHabit->emoji} {$exHabit->title}</b> sudah habis (Batas jam: " . Carbon::parse($exHabit->due_time)->format('H:i') . " WIB). Jangan menyerah, besok dicoba lagi! 💪🚀");
            }
        }
        dump($todayStr);
        // 2. TRIGGER KIRIM NOTIFIKASI REMINDER UTAMA ATAU HASIL TUNDA
        $activeHabits = Habit::where('archived', false)
            ->whereNotExists(function ($query) use ($todayStr) {
                $query->select(DB::raw(1))
                    ->from('habit_logs')
                    ->whereRaw('habit_logs.habit_id = habits.id')
                    ->where('habit_logs.date', $todayStr);
            })->get();
        $activeHabits = Habit::where('archived', false)->get();
        foreach ($activeHabits as $habit) {
            $send = false;
            $isSnoozed = false;
            $cacheKey = ""; // Kunci Gembok

            // Uji jam pengingat utama (Toleransi 60 detik)
            if ($habit->reminder_time) {
                $reminderTimestamp = strtotime($todayStr . ' ' . $habit->reminder_time);
                $diffReminder = $reminderTimestamp - $now;
                if ($diffReminder <= 60 && $diffReminder >= -60) {
                    $send = true;
                    // Bikin Kunci Gembok Utama Harian
                    $cacheKey = "habit_main_sent_{$habit->id}_{$todayStr}";
                }
            }

            // Uji jam tunda (Toleransi 60 detik)
            if ($habit->snooze_until) {
                $snoozeTimestamp = strtotime($todayStr . ' ' . $habit->snooze_until);
                $diffSnooze = $snoozeTimestamp - $now;
                if ($diffSnooze <= 60 && $diffSnooze >= -60) {
                    $send = true;
                    $isSnoozed = true;
                    // Bikin Kunci Gembok Tunda (spesifik pakai jam snooze-nya)
                    $cacheKey = "habit_snooze_sent_{$habit->id}_{$snoozeTimestamp}";
                }
            }

            if (!$send) {
                continue;
            }

            // =========================================================
            // 🔥 INI CORE LOGIC NYA: JIKA SUDAH DIKIRIM, SKIP COK! 🔥
            // =========================================================
            if (Cache::has($cacheKey)) {
                continue;
            }

            // KUNCI GEMBOK SELAMA 24 JAM BIAR LOOP CMD LU GAK NGE-SPAM!
            Cache::put($cacheKey, true, 86400);
            // =========================================================

            dump("MATCH HABIT PROCESSOR TELEGRAM ACTIVED: " . $habit->title);

            $dueLimit = $habit->due_time ? Carbon::parse($habit->due_time) : Carbon::parse('23:59:59');

            if ($isSnoozed) {
                $diff = Carbon::now('Asia/Jakarta')->diff($dueLimit);
                $diffText = "{$diff->h} jam {$diff->i} menit";

                $habitMessage = "⚠️ <b>Sudah 5 menit bay, mau sampai kapan nundanya?</b>\n\n" .
                    "Kurang <b>{$diffText} lagi</b> sampai batas waktu pengerjaan.\n" .
                    "Yuk kerjain sekarang: {$habit->emoji} <b>{$habit->title}</b>!";
            } else {
                $timeFormatted = Carbon::parse($habit->reminder_time)->format('H:i');
                $habitMessage = "🔔 <b>Hai Bay, jam sudah menunjukkan pukul {$timeFormatted}.</b>\n\n" .
                    "Jangan lupa {$habit->emoji} <b>{$habit->title}</b> " . ($habit->description ? "\"{$habit->description}\"" : "");
            }

            $habitMarkup = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Tandai Selesai', 'callback_data' => 'habit_done_direct_' . $habit->id],
                        ['text' => '⏳ Tunda 5 Menit', 'callback_data' => 'habit_snooze_5m_' . $habit->id]
                    ]
                ]
            ];

            $telegram->sendMessage($chatId, $habitMessage, [
                'reply_markup' => json_encode($habitMarkup)
            ]);
        }

        return 0;
    }
}
