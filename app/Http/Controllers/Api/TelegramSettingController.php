<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramSetting;
use App\Models\Task;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TelegramSettingController extends Controller
{
    public function show()
    {
        $setting = TelegramSetting::first();
        return response()->json(['setting' => $setting]);
    }

    public function handle(Request $request)
    {
        if ($request->has('callback_query')) {
            $callbackQuery = $request->input('callback_query');
            $callbackData  = $callbackQuery['data'];
            $chatId        = $callbackQuery['message']['chat']['id'];
            $messageId     = $callbackQuery['message']['message_id'];

            $telegram = new TelegramService();

            if (str_starts_with($callbackData, 'task_done_')) {
                $taskId = str_replace('task_done_', '', $callbackData);
                $task = Task::find($taskId);

                if ($task) {
                    $task->update(['completed_at' => Carbon::now(), 'column_key' => 'done']);
                    $telegram->editMessageText($chatId, $messageId, "✅ <b>Tugas Selesai</b>\n\n• <b>Tugas:</b> {$task->title}\n\nMantap, tugas telah berhasil diperbarui di dalam sistem!");
                }
                return response()->json(['status' => 'success']);
            }

            if (str_starts_with($callbackData, 'task_notdone_')) {
                $taskId = str_replace('task_notdone_', '', $callbackData);
                $task = Task::find($taskId);

                if ($task) {
                    $snoozeKeyboard = [
                        'inline_keyboard' => [[
                            ['text' => '⏳ Tunda 5 Menit', 'callback_data' => 'snooze_5_' . $task->id],
                            ['text' => '⏳ Tunda 15 Menit', 'callback_data' => 'snooze_15_' . $task->id],
                        ]]
                    ];
                    // FIX POIN 5: Menggunakan editMessageText standar passing reply_markup
                    $telegram->editMessageText($chatId, $messageId, "⚠️ <b>Tugas Belum Selesai</b>\n\n• <b>Tugas:</b> {$task->title}\n\nApakah Anda ingin menunda pengingat ini beberapa menit ke depan?", ['reply_markup' => $snoozeKeyboard]);
                }
                return response()->json(['status' => 'success']);
            }
        }
        return response()->json(['status' => 'success']);
    }

    public function save(Request $request) 
    {
        $setting = TelegramSetting::updateOrCreate(
            ['user_id' => 1],
            ['chat_id' => $request->chat_id, 'enabled' => $request->enabled, 'daily_briefing' => $request->daily_briefing]
        );

        if ($setting->enabled && $setting->chat_id) {
            $telegram = new TelegramService();
            $message = "⚡ <b>Koneksi BayTasks Berhasil!</b>\n\nHalo Bayu, Bot lu udah connect 100% sama backend Laravel.\nChat ID: <code>" . $setting->chat_id . "</code>\n\nLet's get started! 🚀🔥";
            $telegram->sendMessage($setting->chat_id, $message);
        }

        return response()->json(['success' => true, 'setting' => $setting]);
    }
}