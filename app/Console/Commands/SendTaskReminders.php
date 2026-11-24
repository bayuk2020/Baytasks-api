<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AppSetting;
use App\Models\Task;
use App\Models\Habit;
use App\Services\IzinNotifikasi;
use App\Services\TelegramService;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache; // Wajib tambah ini buat fitur Gembok
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendTaskReminders extends Command
{
    protected $signature = 'baytasks:reminders';
    protected $description = 'Send Telegram task reminders, complex habits engine, and hourly pulse activity checkers';

    public function handle()
    {
        Log::info('[baytasks:reminders] START');

        $telegram = new TelegramService();

        // =========================================================
        // AMBIL DATA CHAT ID UTAMA
        // =========================================================
        // Syarat yang dipakai bersama oleh KETIGA jalur di command ini (pulse,
        // reminder task, reminder habit): chat_id, saklar Telegram, saklar
        // induk, Sleep Mode, dan jam diam. Kunci kosong karena tiap jalur
        // punya saklarnya sendiri, diperiksa di bawah.
        //
        // Jam diam DIHORMATI di sini -- ketiga jalur ini dipicu kejadian
        // (jatuh tempo, jam habit, denyut), bukan jam yang user pilih sendiri
        // seperti morning brief.
        $izin = IzinNotifikasi::periksa('', true);

        if (!$izin['boleh']) {
            Log::info('[baytasks:reminders] SKIP: ' . $izin['alasan']);

            // $this->info(), BUKAN dump(): keluaran dump() tidak ikut terbaca
            // oleh Artisan::output(), sehingga alasan skip tidak sampai ke
            // halaman Setting saat command dipanggil dari sana. Dua-duanya
            // sama-sama muncul di worker_run.log.
            $this->info('SKIP: ' . $izin['alasan']);

            return 0;
        }

        $setting = $izin['setting'];
        $chatId = $setting->chat_id;

        // BINDING TIMESTAMP UTAMA (UNTUK METODE TOLERANSI RENTANG DETIK)
        $now = time();
        $todayStr = Carbon::now('Asia/Jakarta')->toDateString();

        // =========================================================
        // PENGKONDISIAN A: TRIGGER INTERUPSI AKTIVITAS TIAP 15 MENIT (PULSE CHECKER)
        // =========================================================
        // Interval pulse sekarang diatur dari halaman Setting, tidak lagi beku
        // di angka 60. Bawaannya tetap 60 supaya perilaku lama terjaga.
        // Catatan: komentar judul di atas dan teks dump() di bawah menulis
        // "15 menit" sejak dulu padahal angkanya 60 -- sekarang pakai nilai
        // sebenarnya biar tidak menyesatkan lagi.
        $intervalPulse = max(1, (int) AppSetting::ambil('notif.pulse.interval_minutes'));
        $pulseHidup = (bool) AppSetting::ambil('notif.pulse.enabled');

        if ($pulseHidup && (int)date('i') % $intervalPulse === 0) {

            // Kunci unik berdasarkan tahun, bulan, tanggal, jam, dan menit (misal: pulse_sent_202607210830)
            $pulseKey = "pulse_sent_" . date('YmdHi');

            // Cek apakah di menit ini notifikasi pulse sudah pernah dikirim
            if (!Cache::has($pulseKey)) {

                // Pasang gembok cache selama 2 menit (120 detik) agar tidak double send pada loop 30 detik berikutnya
                Cache::put($pulseKey, true, 120);

                $currentSession = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();

                // Sesi non-idle yang sudah lama tidak bergerak JANGAN dianggap
                // "sibuk". Dulu sesi nyangkut (mis. `leisure_dashboard_waiting`)
                // membuat pulse ini di-skip tiap jam tanpa akhir, jadi pengingat
                // ikut mati. Lihat TelegramSessionManager::MENIT_SESI_BASI.
                if ((new \App\Telegram\Core\TelegramSessionManager())->sesiBasi($currentSession)) {
                    dump("SESI BASI ({$currentSession->step}) -> dipulangkan ke idle, pulse tetap jalan.");
                    DB::table('telegram_sessions')->where('chat_id', $chatId)->update([
                        'step'           => 'idle',
                        'active_task_id' => null,
                        'form_state'     => null,
                        'context_data'   => null,
                        'updated_at'     => now(),
                    ]);
                    $currentSession = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();
                }

                if ($currentSession && (
                    str_starts_with($currentSession->step, 'form_') ||
                    str_contains($currentSession->step, 'leisure') ||
                    $currentSession->step === 'waiting_destination' ||
                    $currentSession->step === 'waiting_task_selection' ||
                    $currentSession->step === 'waiting_subtask' ||
                    $currentSession->step === 'waiting_subtask_confirmation' ||
                    $currentSession->step === 'task_wizard_running'
                )) {
                    dump("SKIP PULSE ({$intervalPulse} menit): Bayu is currently busy handling a module ({$currentSession->step}).");
                } else {
                    dump("MATCH: ACTIVITY PULSE CHECK TRIGGERED (interval {$intervalPulse} menit)");

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
        // Saklar "Reminder jatuh tempo task" di halaman Setting. Dimatikan =
        // daftar kandidatnya kosong, jadi loop di bawah tidak jalan sama
        // sekali -- tidak ada jalur lain yang bisa lolos.
        $tasks = AppSetting::ambil('notif.task_reminders.enabled')
            ? Task::whereNull('completed_at')
                ->where('reminded', false)
                ->whereNotNull('due_at')
                ->get()
            : collect();

        Log::info('[baytasks:reminders] Task candidates dengan due_at & belum reminded: ' . $tasks->count());

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

            // Jejak waktu untuk panel status di halaman Setting.
            AppSetting::catatStatus('status.task_reminders.last_sent_at');

            // Hanya tandai reminded = true di database jika ini adalah alarm terakhir
            if ($statusText === "🚨 <b>WAKTUNYA SEKARANG!</b>") {
                $task->update(['reminded' => true]);
            }
        }

        // =========================================================
        // PENGKONDISIAN C: JALUR REMINDER & EVALUASI HABIT DENGAN GEMBOK CACHE
        // =========================================================
        // PENTING: kolom `archived` di banyak baris habit ternyata NULL, bukan `false`
        // (bukan diisi lewat toggle archive di aplikasi). `where('archived', false)`
        // polos di semantik SQL TIDAK menganggap NULL = false, jadi diam-diam
        // MENGECUALIKAN semua habit yang belum pernah disentuh kolom archived-nya --
        // inilah akar kenapa reminder habit terasa "tidak pernah jalan". Anggap NULL
        // sama dengan belum diarsip di SEMUA query habit di bawah.
        $notArchived = fn($q) => $q->where('archived', false)->orWhereNull('archived');

        // Saklar "Reminder habit" di halaman Setting. Dimatikan = kedua daftar
        // habit di bawah kosong, jadi tidak ada notifikasi habit yang keluar
        // (baik "terlewat" maupun reminder utama).
        $habitHidup = (bool) AppSetting::ambil('notif.habit_reminders.enabled');

        // 1. EVALUASI HABIT YANG TERLEWAT
        $expiredHabits = $habitHidup
            ? Habit::where($notArchived)
                ->whereNotNull('due_time')
                ->whereNotExists(function ($query) use ($todayStr) {
                    $query->select(DB::raw(1))
                        ->from('habit_logs')
                        ->whereRaw('habit_logs.habit_id = habits.id')
                        ->where('habit_logs.date', $todayStr);
                })->get()
            : collect();

        Log::info('[baytasks:reminders] Habit kandidat "terlewat" (due_time sudah lewat, belum ada log hari ini): ' . $expiredHabits->count());

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
        //
        // BUG LAMA: baris ini sebelumnya dibangun dengan whereNotExists (skip habit
        // yang sudah ada log hari ini), TAPI langsung ditimpa baris berikutnya yang
        // query ulang TANPA filter itu sama sekali -- efeknya habit yang sudah
        // ditandai selesai pun tetap kebagian reminder lagi. Sudah digabung jadi
        // satu query yang benar di bawah (filter whereNotExists tetap dipakai).
        $activeHabits = $habitHidup
            ? Habit::where($notArchived)
                ->whereNotExists(function ($query) use ($todayStr) {
                    $query->select(DB::raw(1))
                        ->from('habit_logs')
                        ->whereRaw('habit_logs.habit_id = habits.id')
                        ->where('habit_logs.date', $todayStr);
                })->get()
            : collect();

        Log::info('[baytasks:reminders] Habit aktif (belum diarsip, belum ada log hari ini): ' . $activeHabits->count());

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

            Log::info("[baytasks:reminders] Kirim reminder habit: {$habit->title}", ['habit_id' => $habit->id, 'snoozed' => $isSnoozed]);
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

            // Jejak waktu untuk panel status di halaman Setting.
            AppSetting::catatStatus('status.habit_reminders.last_sent_at');
        }

        Log::info('[baytasks:reminders] DONE');

        return 0;
    }
}
