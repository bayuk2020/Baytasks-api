<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Telegram\Handlers\TaskHandler;
use App\Telegram\Handlers\HabitHandler;
use App\Telegram\Handlers\AiHandler;
use App\Models\Task; // Wajib ditambahkan untuk memanggil model Task

class TelegramWebhookController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function handle(Request $request)
    {
        $update = $request->all();

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }

        return response()->json(['status' => 'success']);
    }

    private function handleMessage($message)
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';

        if (empty($text)) return;

        $textLower = strtolower(trim($text));

        // PENGAMAN 1: Global Cancel Handler (Biar sesi reset ke idle kapan pun lu ketik /cancel)
        if ($textLower === '/cancel') {
            DB::table('telegram_sessions')->updateOrInsert(
                ['chat_id' => $chatId],
                ['step' => 'idle', 'active_task_id' => null, 'form_state' => null, 'context_data' => null, 'updated_at' => now()]
            );
            $this->telegram->sendMessage($chatId, "🛑 <b>Sesi berhasil dibatalkan.</b> Bot kembali ke status standby (Idle).");
            return;
        }

        // Global Command /start
        if ($textLower === '/start') {
            DB::table('telegram_sessions')->updateOrInsert(
                ['chat_id' => $chatId],
                ['step' => 'idle', 'active_task_id' => null, 'form_state' => null, 'context_data' => null, 'updated_at' => now()]
            );

            $menuMarkup = [
                'inline_keyboard' => [
                    [
                        ['text' => '📋 Tasks', 'callback_data' => 'menu_tasks'],
                        ['text' => '🔁 Habits', 'callback_data' => 'menu_habits']
                    ],
                    [
                        ['text' => '☕ Leisure Dashboard', 'callback_data' => 'pulse_santai']
                    ]
                ]
            ];

            $this->telegram->sendMessage($chatId, "👋 <b>Halo Bayu! Selamat datang kembali di Pusat Kendali Aktivitas Anda.</b>\n\nSilakan pilih menu di bawah ini untuk memulai:", [
                'reply_markup' => json_encode($menuMarkup)
            ]);
            return;
        }

        // Ambil session saat ini
        $session = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();
        $step = $session->step ?? 'idle';

        // Tentukan Prefixes untuk Message Text
        $taskPrefixes = ['form_', 'waiting_', 'wiz_', 'manual_board_', 'select_task_', 'action_', 'set_prio_', 'sub_', 'intercept_target_', 'task_wizard_running'];
        $habitPrefixes = ['habit_', 'menu_habits', 'leisure_'];

        if (Str::is($taskPrefixes, $step) || $step === 'task_wizard_running') {
            (new TaskHandler($chatId, $text, null, null))->execute();
        } elseif (Str::is($habitPrefixes, $step) || str_contains($step, 'leisure')) {
            (new HabitHandler($chatId, $text, null, null))->execute();
        } elseif ($step === 'idle') {
            // Sesi benar-benar nganggur & teksnya bukan command dikenal -> lempar ke AI
            // biar bisa ngerti chat bebas & manggil tools (create_task, log_habit, dst).
            (new AiHandler($chatId, $text))->execute();
        } else {
            // Step tidak dikenal (kemungkinan sisa sesi lama) -> fallback ke perilaku lama.
            (new TaskHandler($chatId, $text, null, null))->execute();
        }
    }

    private function handleCallback($callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $callbackData = $callbackQuery['data'];

        // =========================================================
        // 🔥 INTERCEPTOR: TANGKAP TOMBOL DARI PENGINGAT TASK DULU
        // =========================================================

        // JIKA TOMBOL "SUDAH" DIKLIK
        if (str_starts_with($callbackData, 'task_done_')) {
            $taskId = str_replace('task_done_', '', $callbackData);
            $task = Task::find($taskId);

            if ($task) {
                $task->update([
                    'completed_at' => now(),
                    'reminded' => true
                ]);
                $this->telegram->editMessageText($chatId, $messageId, "✅ Sip! Tugas <b>{$task->title}</b> sudah berhasil ditandai selesai. Mantap kerjanya Bay!");
            } else {
                $this->telegram->editMessageText($chatId, $messageId, "⚠️ Oops, data tugas tidak ditemukan di database.");
            }
            return; // Hentikan script di sini agar tidak dilanjutkan ke TaskHandler
        }

        // JIKA TOMBOL "BELUM" DIKLIK
        if (str_starts_with($callbackData, 'task_notdone_')) {
            $taskId = str_replace('task_notdone_', '', $callbackData);
            $task = Task::find($taskId);

            if ($task) {
                $this->telegram->editMessageText($chatId, $messageId, "❌ Oke, tugas <b>{$task->title}</b> masih gantung. Jangan lupa diselesaikan ya!");
            } else {
                $this->telegram->editMessageText($chatId, $messageId, "⚠️ Oops, data tugas tidak ditemukan di database.");
            }
            return; // Hentikan script di sini
        }

        // =========================================================
        // FIX ROUTING (BALIK KE LOGIKA LAMA)
        // =========================================================
        if (
            Str::startsWith($callbackData, ['menu_tasks', 'task_', 'waiting_', 'wiz_', 'manual_board_', 'select_task_', 'action_', 'set_prio_', 'sub_', 'intercept_target_']) ||
            str_contains($callbackData, 'pulse_kerja') ||
            str_contains($callbackData, 'pulse_belajar') ||
            str_contains($callbackData, 'pulse_lainnya') ||
            str_contains($callbackData, 'pulse_back_to_menu')
        ) {
            (new TaskHandler($chatId, null, $callbackData, $messageId))->execute();
        } elseif (Str::startsWith($callbackData, ['menu_habits', 'habit_']) || str_contains($callbackData, 'pulse_santai') || str_contains($callbackData, 'leisure_')) {
            (new HabitHandler($chatId, null, $callbackData, $messageId))->execute();
        }
    }
}
