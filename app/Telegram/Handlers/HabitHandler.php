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
        
        // Ambil session secara otomatis lewat chat_id
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
        }
    }

private function handleTextInput()
    {
        $textLower = strtolower(trim($this->text));

        // PENGAMAN GLOBAL: Berlaku di seluruh step HabitHandler
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

            // Sensor A: Jika user ketik format "{nomor} done" (Contoh: "1 done")
            if (preg_match('/^(\d+)\s+done$/i', $textLower, $matches)) {
                $index = (int)$matches[1] - 1;
                if (isset($openTasks[$index])) {
                    $task = $openTasks[$index];
                    \App\Models\Task::where('id', $task->id)->update(['completed_at' => now(), 'column_key' => 'done']);
                    $this->telegram->sendMessage($this->chatId, "✅ Mantap kawan! Tugas \"<b>" . htmlspecialchars($task->title, ENT_QUOTES, 'UTF-8') . "</b>\" berhasil diselesaikan!");
                    $this->showLeisureTasks(); // Auto refresh daftar sisa tugas terbaru
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan tugas salah.");
                }
                return;
            }

            // Sensor B: Jika HANYA MENGETIK ANGKA (Contoh: ketik "1" untuk buka detail manipulasi tugas)
            if (is_numeric($textLower)) {
                $index = (int)$textLower - 1;
                if (isset($openTasks[$index])) {
                    $task = $openTasks[$index];
                    
                    // Bypass Dinamis: Oper kendali kontrol ke TaskHandler bawaan
                    $taskHandler = new \App\Telegram\Handlers\TaskHandler($this->chatId, null, null, $this->messageId);
                    $taskHandler->renderTaskSensation($task->id, true);
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan tugas salah kawan.");
                }
                return;
            }

            // Jebakan Batman: Jika user mengetik ngasal pas lihat list tugas santai
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
        // MODULE 2: PENGECEKAN INTERAKSI HABIT VIA DASHBOARD SANTAI
        // =========================================================
        if ($this->session && $this->session->step === 'leisure_view_habits') {
            $today = Carbon::today()->toDateString();
            $habits = Habit::orderBy('title', 'asc')->get();

            // Cek apakah formatnya bener (misal: "2 done" atau "2 undone")
            if (preg_match('/^(\d+)\s+(done|undone)$/i', $textLower, $matches)) {
                $index = (int)$matches[1] - 1;
                $action = strtolower($matches[2]);

                if (isset($habits[$index])) {
                    $habit = $habits[$index];
                    
                    if ($action === 'done') {
                        DB::table('habit_logs')->updateOrInsert(
                            ['habit_id' => $habit->id, 'date' => $today],
                            ['completed' => 1, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]
                        );
                        $this->telegram->sendMessage($this->chatId, "👍 Habit <b>" . htmlspecialchars($habit->title, ENT_QUOTES, 'UTF-8') . "</b> berhasil dicentang Selesai kawan!");
                    } else {
                        DB::table('habit_logs')->where('habit_id', $habit->id)->where('date', $today)->delete();
                        $this->telegram->sendMessage($this->chatId, "🔄 Habit <b>" . htmlspecialchars($habit->title, ENT_QUOTES, 'UTF-8') . "</b> diaktifkan kembali!");
                    }
                    
                    $this->showLeisureHabits(); // Auto refresh list sisa habit terbaru
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Urutan habit salah kawan.");
                }
            } 
            // Blok jebakan batman kalau user ngetik ngasal di menu habit!
            else {
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
        // MODULE 3: PENGARSIPAN MANUAL LOG SANTAI KELAR KEWENANGAN
        // =========================================================
        if ($this->session && $this->session->step === 'leisure_activity') {
            DB::table('memories')->insert([
                'type' => 'leisure', 'source' => 'telegram', 'title' => 'Santai Log', 'content' => "User sedang santai: {$this->text}",
                'tags' => json_encode(['leisure', 'telegram']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()
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

        // JIKA SEMUA BERSIH: Bebas Bersantai
        if ($openTasksCount === 0 && $pendingHabitsCount === 0) {
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 Kembali', 'callback_data' => 'pulse_back_to_menu']]]];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "Selamat beristirahat bay, semua habbit dan task sudah di kerjakan semua, congrats for your day 🔥\n\nKetik aktivitas santaimu saat ini :", ['reply_markup' => $keyboard]);
            $this->sessionManager->updateSession($this->chatId, ['step' => 'leisure_activity']);
            return;
        }

        // JIKA ADA YANG NUNGGAK: Tampilkan Peringatan Dashboard Guard
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
        // Tarik gabungan sisa tugas aktif dari Board Kerjaan (2) dan Personal (4) sekaligus kawan
        $openTasks = \App\Models\Task::whereIn('board_id', [2, 4])->where('column_key', '!=', 'done')->get();
        
        $txt = "📋 <b>Daftar Sisa Tugas Hari Ini:</b>\n\n";
        $buttons = [];
        
        foreach ($openTasks as $i => $t) {
            $boardLabel = ($t->board_id == 2) ? 'Kerjaan' : 'Personal';
            $txt .= ($i + 1) . ". 📌 <b>" . htmlspecialchars($t->title, ENT_QUOTES, 'UTF-8') . "</b> (Board {$boardLabel})\n";
            
            // FIX KUNCI: Inject barisan tombol inline keyboard biar lu tinggal klik tanpa bingung mau ngapain!
            $buttons[] = [['text' => "🎯 Kelola Tugas " . ($i + 1), 'callback_data' => 'select_task_' . $t->id]];
        }

        // Teks instruksi navigasi pengetikan teks manual
        $txt .= "\n💡 <i>Ketik angka urut (contoh: <code>1</code>) atau klik tombol di bawah untuk mengelola detail & aksi tugas.\n" .
                "💡 Ketik <code>{nomor} done</code> untuk menutup tugas langsung.\n" .
                "💡 Ketik <code>/cancel</code> jika ingin membatalkan.</i>";

        // Tombol navigasi kembali ditaruh di baris paling bawah sendiri kawan
        $buttons[] = [['text' => '🔙 Kembali ke Dashboard', 'callback_data' => 'leisure_back_dashboard']];
        
        // Kirim text beserta full inline keyboard markup ke Telegram API
        $this->telegram->editMessageText($this->chatId, $this->messageId, $txt, [
            'reply_markup' => ['inline_keyboard' => $buttons]
        ]);
        
        // Amankan step session biar controller tahu lu lagi di posisi milih sisa tugas
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
        
        // UX IMPROVEMENT: Informasi navigasi yang jelas biar user gak bingung keluar sesi
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
                'habit_id' => $habitId, 'date' => $today, 'completed' => 1, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()
            ]); 
        }
        $this->showHabitList();
    }
}