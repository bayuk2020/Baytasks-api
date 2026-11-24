<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\TelegramSetting;

/**
 * Satu pintu untuk pertanyaan "boleh kirim notifikasi Telegram sekarang?".
 *
 * Sebelum kelas ini ada, tiap pengirim memeriksa syaratnya sendiri-sendiri
 * dan hasilnya tidak konsisten: saklar `enabled` dipatuhi oleh
 * SendTaskReminders tapi diabaikan SendMorningBrief dan SendNightlySummary,
 * sedangkan `daily_briefing` hanya dipatuhi oleh Services/DailyBriefing.
 * Jadi mematikan "Enabled" di halaman Setting tidak benar-benar
 * menghentikan brief pagi -- saklar yang terasa induk padahal bukan.
 *
 * Semua pemeriksaan sekarang lewat sini, berurutan, dan setiap penolakan
 * membawa alasan yang bisa ditulis ke log maupun ditampilkan di panel
 * status halaman Setting.
 */
class IzinNotifikasi
{
    /**
     * @param  string      $kunciEnabled    kunci AppSetting untuk notifikasi ini,
     *                                      mis. 'notif.morning_brief.enabled'.
     * @param  bool        $hormatiJamDiam  true untuk notifikasi yang dipicu
     *                                      kejadian (reminder jatuh tempo, pulse).
     *                                      false untuk yang jamnya dipilih user
     *                                      sendiri (morning brief, nightly
     *                                      summary, daily briefing) -- kalau user
     *                                      menyetel brief jam 08:00, dia memang
     *                                      mau jam 08:00, tidak perlu ditawar
     *                                      lagi oleh jam diam.
     * @return array{boleh: bool, alasan: string, setting: ?TelegramSetting}
     */
    public static function periksa(
        string $kunciEnabled,
        bool $hormatiJamDiam = true,
        ?TelegramSetting $setting = null
    ): array {
        $setting = $setting ?? TelegramSetting::first();

        if (! $setting || empty($setting->chat_id)) {
            return self::tolak('Telegram belum diatur (chat_id kosong).', $setting);
        }

        if (! $setting->enabled) {
            return self::tolak('Saklar Telegram mati.', $setting);
        }

        if (! AppSetting::ambil('notif.master')) {
            return self::tolak('Saklar induk notifikasi mati.', $setting);
        }

        if ($kunciEnabled !== '' && ! AppSetting::ambil($kunciEnabled)) {
            return self::tolak('Notifikasi ini dimatikan di halaman Setting.', $setting);
        }

        // sedangTidur() sekaligus membangunkan Sleep Mode yang sudah lewat
        // jam bangunnya -- lihat TelegramSetting::sedangTidur().
        if ($setting->sedangTidur()) {
            return self::tolak('Sleep Mode masih aktif.', $setting);
        }

        if ($hormatiJamDiam && AppSetting::sedangJamDiam()) {
            return self::tolak('Sedang di dalam jam diam.', $setting);
        }

        return ['boleh' => true, 'alasan' => '', 'setting' => $setting];
    }

    private static function tolak(string $alasan, ?TelegramSetting $setting): array
    {
        return ['boleh' => false, 'alasan' => $alasan, 'setting' => $setting];
    }
}
