<?php

namespace App\Telegram\Handlers;

use App\Services\TelegramService;
use App\Telegram\Core\TelegramSessionManager;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HabitHandler
{
    protected TelegramService $telegram;
    protected TelegramSessionManager $sessionManager;
    protected int $chatId;
    protected ?string $text;
    protected ?string $callbackData;
    protected ?int $messageId;
    protected $session;

    public function __construct($chatId, $text = null, $callbackData = null, $messageId = null)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->callbackData = $callbackData;
        $this->messageId = $messageId;
        $this->telegram = new TelegramService();
        $this->sessionManager = new TelegramSessionManager();
        $this->session = $this->sessionManager->getSession($chatId);
    }

    public function execute()
    {
        if ($this->callbackData) {
            $this->handleCallback();
        } elseif ($this->text) {
            $this->handleTextInput();
        }
    }

    private function handleCallback()
    {
        // =========================================================
        // HANDLER ACTIONS TOMBOL INTERAKTIF REMINDER HABIT
        // =========================================================
        if (str_starts_with($this->callbackData, 'habit_done_direct_')) {
            $this->handleHabitDoneDirect();
            return;
        }

        if (str_starts_with($this->callbackData, 'habit_snooze_5m_')) {
            $this->handleHabitSnooze();
            return;
        }

        // =========================================================
        // MENU UTAMA & DASHBOARD
        // =========================================================
        if ($this->callbackData === 'menu_habit') {
            $this->showHabitList();
        } elseif (str_starts_with($this->callbackData, 'toggle_habit_')) {
            $this->toggleHabit();
        } elseif ($this->callbackData === 'pulse_santai') {
            $this->checkLeisureDashboard();
        } elseif ($this->callbackData === 'leisure_view_tasks') {
            $this->showLeisureTasks();
        } elseif ($this->callbackData === 'leisure_view_habits') {
            $this->showLeisureHabits();
        } elseif ($this->callbackData === 'leisure_back_dashboard') {
            $this->renderLeisureDashboardMenu();
        } elseif ($this->callbackData === 'leisure_delay_work') {
            $this->telegram->sendMessage($this->chatId, "👌 <b>Oke nanti jangan lupa kerjain yaa kawan!</b> Selamat beristirahat sejenak.");
            $this->sessionManager->clearSession($this->chatId);
        }
    }

    // =========================================================
    // LOGIKA SELESAI HABIT VIA TOMBOL (DENGAN CATATAN TUNDA)
    // =========================================================
    private function handleHabitDoneDirect()
    {
        $habitId = (int) str_replace('habit_done_direct_', '', $this->callbackData);
        $habit = Habit::find($habitId);
        $today = Carbon::today()->toDateString();

        if ($habit) {
            $totalSnoozeCount = DB::table('memories')
                ->where('type', 'habit_snooze_log')
                ->where('title', 'like', "%Habit ID: {$habit->id}%")
                ->whereDate('created_at', $today)->count();

            $snoozeMinutes = $totalSnoozeCount * 5;
            $notes = $snoozeMinutes > 0 ? "Tertunda selama {$snoozeMinutes} menit (Total {$totalSnoozeCount}x tunda)." : "Dikerjakan tepat waktu kawan!";

            DB::table('habit_logs')->updateOrInsert(
                ['habit_id' => $habit->id, 'date' => $today],
                [
                    'completed' => 1,
                    'completed_at' => now(),
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            $habit->update(['snooze_until' => null]);
            $habit->increment('streak');

            $this->telegram->editMessageText($this->chatId, $this->messageId, "👍 Mantap, Bay! Habit <b>" . htmlspecialchars($habit->title, ENT_QUOTES, 'UTF-8') . "</b> selesai! ({$notes})");
        }
        $this->sessionManager->clearSession($this->chatId);
    }

    // =========================================================
    // LOGIKA TUNDA 5 MENIT VIA TOMBOL
    // =========================================================
    private function handleHabitSnooze()
    {
        $habitId = (int) str_replace('habit_snooze_5m_', '', $this->callbackData);
        $habit = Habit::find($habitId);

        if ($habit) {
            $dueLimit = $habit->due_time ? Carbon::parse($habit->due_time) : Carbon::parse('23:59:59');
            $nextSnooze = Carbon::now('Asia/Jakarta')->addMinutes(5);

            if ($nextSnooze->greaterThanOrEqualTo($dueLimit)) {
                $this->telegram->sendMessage($this->chatId, "⚠️ Waktu tunda sudah mepet batas akhir pengerjaan ({$dueLimit->format('H:i')}), Bay! Selesaikan sekarang atau habit ini akan dianggap gagal!");
                return;
            }

            DB::table('memories')->insert([
                'type' => 'habit_snooze_log',
                'source' => 'telegram',
                'title' => "Habit ID: {$habit->id}",
                'content' => "User menunda pengerjaan habit: {$habit->title}",
                'tags' => json_encode(['habit', 'snooze']),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $habit->update(['snooze_until' => $nextSnooze->format('H:i:00')]);

            $this->telegram->editMessageText($this->chatId, $this->messageId, "⏳ Oke Bay, aku ingetin lagi 5 menit dari sekarang (Jam: " . $nextSnooze->format('H:i') . ").");
        }
        $this->sessionManager->clearSession($this->chatId);
    }

    private function handleTextInput()
    {
        $textLower = strtolower(trim($this->text));

        if ($textLower === '/cancel' || $textLower === 'cancel') {
            $this->telegram->sendMessage($this->chatId, "🚫 <b>Sesi dibatalkan.</b> Kembali ke menu standby (Idle) kawan.");
            $this->sessionManager->clearSession($this->chatId);
            return;
        }

        // =========================================================
        // MODULE 1: PENGECEKAN INTERAKSI TASK VIA DASHBOARD SANTAI
        // =========================================================
        if ($this->session && $this->session->step === 'leisure_view_tasks') {
            $openTasks = \App\Models\Task::whereIn('board_id', [2, 4])->where('column_key', '!=', 'done')->get();

            if (preg_match('/^(\d+)\s+done$/i', $textLower, $matches)) {
                $index = (int)$matches[1] - 1;
                if (isset($openTasks[$index])) {
                    $task = $openTasks[$index];
                    \App\Models\Task::where('id', $task->id)->update(['completed_at' => now(), 'column_key' => 'done']);
                    $this->telegram->sendMessage($this->chatId, "✅ Mantap kawan! Tugas \"<b>" . htmlspecialchars($task->title, ENT_QUOTES, 'UTF-8') . "</b>\" berhasil diselesaikan!");
                    $this->showLeisureTasks();
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan tugas salah.");
                }
                return;
            }

            if (is_numeric($textLower)) {
                $index = (int)$textLower - 1;
                if (isset($openTasks[$index])) {
                    $task = $openTasks[$index];
                    $taskHandler = new \App\Telegram\Handlers\TaskHandler($this->chatId, null, null, $this->messageId);
                    $taskHandler->renderTaskSensation($task->id, true);
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan tugas salah kawan.");
                }
                return;
            }

            $this->telegram->sendMessage(
                $this->chatId,
                "⚠️ <b>Maaf kawan, aku tidak mengenali perintah itu.</b>\n\n" .
                    "💡 <i>Ketik angka urut <code>{nomor}</code> untuk buka detail & tombol aksi lengkap (Contoh: <code>1</code>).\n" .
                    "💡 Ketik <code>{nomor} done</code> untuk langsung menyelesaikannya.\n" .
                    "💡 Ketik <code>/cancel</code> untuk keluar menu kawan.</i>"
            );
            return;
        }

        // =========================================================
        // FIX KUNCI MODULE 2: INTERAKSI TEKS MANUAL HABIT (SINKRON DENGAN NOTES & SNOOZE)
        // =========================================================
        if ($this->session && $this->session->step === 'leisure_view_habits') {
            $today = Carbon::today()->toDateString();
            $habits = Habit::orderBy('title', 'asc')->get();

            if (preg_match('/^(\d+)\s+(done|undone)$/i', $textLower, $matches)) {
                $index = (int)$matches[1] - 1;
                $action = strtolower($matches[2]);

                if (isset($habits[$index])) {
                    $habit = $habits[$index];

                    if ($action === 'done') {
                        // 1. Hitung total tunda hari ini dari tabel memories
                        $totalSnoozeCount = DB::table('memories')
                            ->where('type', 'habit_snooze_log')
                            ->where('title', 'like', "%Habit ID: {$habit->id}%")
                            ->whereDate('created_at', $today)->count();

                        $snoozeMinutes = $totalSnoozeCount * 5;
                        $notes = $snoozeMinutes > 0 ? "Tertunda selama {$snoozeMinutes} menit (Total {$totalSnoozeCount}x tunda)." : "Dikerjakan tepat waktu kawan!";

                        // 2. Inject catatan tunda ke log database
                        DB::table('habit_logs')->updateOrInsert(
                            ['habit_id' => $habit->id, 'date' => $today],
                            [
                                'completed' => 1,
                                'completed_at' => now(),
                                'notes' => $notes,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]
                        );

                        // 3. Reset flag tunda internal & perpanjang streak
                        $habit->update(['snooze_until' => null]);
                        $habit->increment('streak');

                        $this->telegram->sendMessage($this->chatId, "👍 Habit <b>" . htmlspecialchars($habit->title, ENT_QUOTES, 'UTF-8') . "</b> berhasil dicentang Selesai kawan! ({$notes})");
                    } else {
                        DB::table('habit_logs')->where('habit_id', $habit->id)->where('date', $today)->delete();
                        $habit->update(['streak' => 0]); // Reset streak jika dibatalkan kawan
                        $this->telegram->sendMessage($this->chatId, "🔄 Habit <b>" . htmlspecialchars($habit->title, ENT_QUOTES, 'UTF-8') . "</b> diaktifkan kembali!");
                    }

                    $this->showLeisureHabits();
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Urutan habit salah kawan.");
                }
            } else {
                $this->telegram->sendMessage(
                    $this->chatId,
                    "⚠️ <b>Maaf kawan, aku tidak mengenali perintah itu.</b>\n\n" .
                        "💡 <i>Ketik format <code>{nomor} done</code> (contoh: <code>1 done</code>).\n" .
                        "💡 Ketik format <code>{nomor} undone</code> (contoh: <code>1 undone</code>) untuk batal centang.\n" .
                        "💡 Ketik <code>/cancel</code> untuk keluar.</i>"
                );
            }
            return;
        }

        // =========================================================
        // MODULE 3: PENGARSIPAN MANUAL LOG SANTAI
        // =========================================================
        if ($this->session && $this->session->step === 'leisure_activity') {
            DB::table('memories')->insert([
                'type' => 'leisure',
                'source' => 'telegram',
                'title' => 'Santai Log',
                'content' => "User sedang santai: {$this->text}",
                'tags' => json_encode(['leisure', 'telegram']),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $this->telegram->sendMessage($this->chatId, "👌 <b>Oke siap, Bay!</b> Selamat melanjutkan aktivitasmu kawan, santai aja dulu sejenak.");
            $this->sessionManager->clearSession($this->chatId);
        }
    }

    private function checkLeisureDashboard()
    {
        $today = Carbon::today()->toDateString();
        $openTasksCount = Task::whereIn('board_id', [2, 4])->where('column_key', '!=', 'done')->count();

        $totalHabits = Habit::count();
        $completedHabits = DB::table('habit_logs')->where('date', $today)->where('completed', 1)->count();
        $pendingHabitsCount = $totalHabits - $completedHabits;

        if ($openTasksCount === 0 && $pendingHabitsCount === 0) {
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 Kembali', 'callback_data' => 'pulse_back_to_menu']]]];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "Selamat beristirahat bay, semua habbit and task sudah di kerjakan semua, congrats for your day 🔥\n\nKetik aktivitas santaimu saat ini :", ['reply_markup' => $keyboard]);
            $this->sessionManager->updateSession($this->chatId, ['step' => 'leisure_activity']);
            return;
        }

        $this->renderLeisureDashboardMenu();
    }

    private function renderLeisureDashboardMenu()
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 Buka Tasks', 'callback_data' => 'leisure_view_tasks'], ['text' => '🔥 Buka Habits', 'callback_data' => 'leisure_view_habits']],
                [['text' => '🔙 Kembali', 'callback_data' => 'pulse_back_to_menu']]
            ]
        ];
        $msg = "Selamat beristirahat bay, anyway lagi ngapain emang? aku hanya pengen ngingetin ada beberapa task & habbit yang belum kamu selesaikan hari ini kawan.";

        if ($this->messageId) {
            $this->telegram->editMessageText($this->chatId, $this->messageId, $msg, ['reply_markup' => $keyboard]);
        } else {
            $this->telegram->sendMessage($this->chatId, $msg, ['reply_markup' => $keyboard]);
        }
        $this->sessionManager->updateSession($this->chatId, ['step' => 'leisure_dashboard_waiting']);
    }

    private function showLeisureTasks()
    {
        $openTasks = \App\Models\Task::whereIn('board_id', [2, 4])->where('column_key', '!=', 'done')->get();
        $txt = "📋 <b>Daftar Sisa Tugas Hari Ini:</b>\n\n";
        $buttons = [];

        foreach ($openTasks as $i => $t) {
            $boardLabel = ($t->board_id == 2) ? 'Kerjaan' : 'Personal';
            $txt .= ($i + 1) . ". 📌 <b>" . htmlspecialchars($t->title, ENT_QUOTES, 'UTF-8') . "</b> (Board {$boardLabel})\n";
            $buttons[] = [['text' => "🎯 Kelola Tugas " . ($i + 1), 'callback_data' => 'select_task_' . $t->id]];
        }

        $txt .= "\n💡 <i>Ketik angka urut (contoh: <code>1</code>) atau klik tombol di bawah untuk mengelola detail & aksi tugas.\n" .
            "💡 Ketik <code>{nomor} done</code> untuk menutup tugas langsung.\n" .
            "💡 Ketik <code>/cancel</code> jika ingin membatalkan.</i>";

        $buttons[] = [['text' => '🔙 Kembali ke Dashboard', 'callback_data' => 'leisure_back_dashboard']];

        $this->telegram->editMessageText($this->chatId, $this->messageId, $txt, [
            'reply_markup' => ['inline_keyboard' => $buttons]
        ]);

        $this->sessionManager->updateSession($this->chatId, ['step' => 'leisure_view_tasks']);
    }

    private function showLeisureHabits()
    {
        $today = Carbon::today()->toDateString();
        $habits = Habit::orderBy('title', 'asc')->get();
        $completedIds = DB::table('habit_logs')->where('date', $today)->where('completed', 1)->pluck('habit_id')->toArray();

        $txt = "🔥 <b>Daftar Sisa Habit Hari Ini:</b>\n\n";
        foreach ($habits as $i => $h) {
            $status = in_array($h->id, $completedIds) ? "✅ (dicoret selesai)" : "❌ (belum dikerjakan)";
            $cleanTitle = htmlspecialchars($h->title, ENT_QUOTES, 'UTF-8');
            $txt .= ($i + 1) . ". {$cleanTitle} - {$status}\n";
        }

        $txt .= "\n💡 <i>Ketik <code>{nomor} done</code> untuk mencentang.\n" .
            "💡 Ketik <code>{nomor} undone</code> untuk membatalkan centang.\n" .
            "💡 Ketik <code>/cancel</code> atau klik tombol di bawah jika sudah selesai berkelana.</i>";

        $keyboard = ['inline_keyboard' => [[['text' => '🔙 Kembali ke Dashboard', 'callback_data' => 'leisure_back_dashboard']]]];

        if ($this->messageId) {
            $this->telegram->editMessageText($this->chatId, $this->messageId, $txt, ['reply_markup' => $keyboard]);
        } else {
            $this->telegram->sendMessage($this->chatId, $txt, ['reply_markup' => $keyboard]);
        }

        $this->sessionManager->updateSession($this->chatId, ['step' => 'leisure_view_habits']);
    }

    private function showHabitList()
    {
        $today = Carbon::today()->toDateString();
        $habits = Habit::orderBy('title', 'asc')->get();
        $completedIds = DB::table('habit_logs')->where('date', $today)->where('completed', 1)->pluck('habit_id')->toArray();

        $buttons = [];
        foreach ($habits as $habit) {
            $icon = in_array($habit->id, $completedIds) ? '✅' : '❌';
            $buttons[] = [['text' => "{$icon} {$habit->title}", 'callback_data' => 'toggle_habit_' . $habit->id]];
        }

        $this->telegram->editMessageText($this->chatId, $this->messageId, "🔥 <b>Habit Tracker Harian</b>", ['reply_markup' => ['inline_keyboard' => $buttons]]);
    }

    private function toggleHabit()
    {
        $habitId = (int) str_replace('toggle_habit_', '', $this->callbackData);
        $today = Carbon::today()->toDateString();

        $log = DB::table('habit_logs')->where('habit_id', $habitId)->where('date', $today)->first();

        if ($log) {
            DB::table('habit_logs')->where('habit_id', $habitId)->where('date', $today)->delete();
        } else {
            DB::table('habit_logs')->insert([
                'habit_id' => $habitId,
                'date' => $today,
                'completed' => 1,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        $this->showHabitList();
    }
}
