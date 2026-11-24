<?php

namespace App\Telegram\Core;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TelegramSessionManager
{
    /**
     * Batas waktu diam sebelum sebuah sesi dianggap ditinggalkan.
     *
     * Kenapa perlu: step sesi hanya berpindah kalau user menekan tombol atau
     * mengirim teks yang cocok. Kalau user menutup Telegram di tengah sebuah
     * modul (mis. step `leisure_dashboard_waiting`), step itu menetap SELAMANYA.
     * Akibatnya dua hal rusak sekaligus:
     *   1. TelegramWebhookController tidak pernah melempar teks ke AiHandler,
     *      karena AI hanya dipanggil saat step == 'idle';
     *   2. pulse tiap jam di SendTaskReminders ikut di-skip, karena sesi
     *      non-idle dianggap "Bayu sedang sibuk".
     * Dengan ambang ini, sesi yang tidak bergerak dianggap selesai dengan
     * sendirinya, jadi bot tidak bisa terkunci permanen lagi.
     */
    public const MENIT_SESI_BASI = 30;

    /**
     * Apakah sesi ini sudah "basi" -- non-idle tetapi tidak bergerak lebih lama
     * dari MENIT_SESI_BASI. updateSession() dan clearSession() selalu menyetel
     * updated_at, jadi kolom itu memang jejak aktivitas terakhir sesi.
     */
    public function sesiBasi($session): bool
    {
        if (!$session) {
            return false;
        }

        $step = $session->step ?? 'idle';
        if ($step === '' || $step === 'idle') {
            return false;
        }

        if (empty($session->updated_at)) {
            return false;
        }

        try {
            return Carbon::parse($session->updated_at)->lt(now()->subMinutes(self::MENIT_SESI_BASI));
        } catch (\Throwable $e) {
            // updated_at tidak bisa dibaca -> jangan sok tahu, anggap masih hidup.
            return false;
        }
    }

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