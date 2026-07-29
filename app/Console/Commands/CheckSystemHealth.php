<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Services\TelegramService;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Cache;

class CheckSystemHealth extends Command
{
    protected $signature = 'baytasks:healthcheck';
    protected $description = 'Monitor Local Laravel & Cloudflare Tunnel availability';

    public function handle()
    {
        $setting = TelegramSetting::first();
        if (!$setting || !$setting->chat_id) return 1;

        $chatId = $setting->chat_id;
        $telegram = new TelegramService();

        // 1. CEK SERVER LOKAL (php artisan serve)
        $localStatus = false;
        try {
            $response = Http::timeout(5)->get('http://127.0.0.1:8000');
            $localStatus = $response->successful() || $response->status() === 404;
        } catch (\Exception $e) {
            $localStatus = false;
        }

        // 2. CEK CLOUDFLARE TUNNEL (PUBLIC API)
        $tunnelStatus = false;
        try {
            $response = Http::timeout(8)->get('https://api.kabyra.my.id');
            $tunnelStatus = $response->successful() || $response->status() === 404;
        } catch (\Exception $e) {
            $tunnelStatus = false;
        }

        // 3. LOGIKA NOTIFIKASI TELEGRAM (Gunakan Cache agar tidak spam saat down)
        if (!$localStatus || !$tunnelStatus) {
            $cacheKey = "healthcheck_alert_sent";

            if (!Cache::has($cacheKey)) {
                $msg = "🚨 <b>SYSTEM ALERT: SERVICES DOWN!</b>\n\n";
                $msg .= "• Laravel Local (Port 8000): " . ($localStatus ? "✅ ONLINE" : "❌ <b>OFFLINE/REFUSED</b>") . "\n";
                $msg .= "• Cloudflare Tunnel (api.kabyra.my.id): " . ($tunnelStatus ? "✅ ONLINE" : "❌ <b>OFFLINE/UNREACHABLE</b>") . "\n\n";
                $msg .= "⚠️ <i>Mohon cek server Laragon/Tunnel di PC kamu kawan!</i>";

                $telegram->sendMessage($chatId, $msg);

                // Mencegah spam notifikasi down setiap menit (Kunci alarm selama 15 menit)
                Cache::put($cacheKey, true, 900);
            }
        } else {
            // Jika sudah kembali normal, hapus pengunci alert
            Cache::forget("healthcheck_alert_sent");
        }

        return 0;
    }
}
