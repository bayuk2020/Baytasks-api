<?php

namespace App\Telegram\Core;

use Illuminate\Support\Facades\DB;

class TelegramSessionManager
{
    public function getSession(int $chatId)
    {
        $session = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();
        
        if (!$session) {
            DB::table('telegram_sessions')->insert([
                'chat_id' => $chatId,
                'step' => 'idle',
                'active_task_id' => null,
                'context_data' => null,
                'form_state' => null,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $session = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();
        }
        
        return $session;
    }

    public function updateSession(int $chatId, array $data)
    {
        $data['updated_at'] = now();
        DB::table('telegram_sessions')->where('chat_id', $chatId)->update($data);
    }

    public function clearSession(int $chatId)
    {
        DB::table('telegram_sessions')->where('chat_id', $chatId)->update([
            'step' => 'idle',
            'active_task_id' => null,
            'form_state' => null,
            'context_data' => null,
            'updated_at' => now()
        ]);
    }
}