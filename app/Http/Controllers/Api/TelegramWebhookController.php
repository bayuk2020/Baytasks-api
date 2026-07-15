<?php

namespace App\Http\Controllers\Api; 

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Telegram\Handlers\TaskHandler;
use App\Telegram\Handlers\HabitHandler;

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
        } else {
            (new TaskHandler($chatId, $text, null, null))->execute();
        }
    }

private function handleCallback($callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $callbackData = $callbackQuery['data'];

        // FIX ROUTING: Deteksi secara langsung tanpa array ribet, jika mengandung kata 'pulse_' atau 'menu_tasks' langsung hantam ke TaskHandler!
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