<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Setting aplikasi berbentuk key-value.
 *
 * Satu-satunya sumber kebenaran untuk perilaku yang dulu beku di dalam kode
 * (jam kirim brief, interval pulse, notifikasi mana yang hidup). Nilai yang
 * belum pernah disimpan dibaca dari DAFTAR di bawah, jadi aplikasi tetap
 * jalan walau tabelnya masih kosong.
 *
 * ATURAN PENTING: pembacaan setting TIDAK BOLEH membuat aplikasi mati. Semua
 * akses di sini dibungkus try/catch dan jatuh ke nilai bawaan kalau database
 * tidak bisa dihubungi. Alasannya konkret: Kernel::schedule() membaca jam
 * kirim dari sini SETIAP MENIT. Kalau pembacaan itu melempar exception,
 * seluruh scheduler berhenti -- dan itu persis jenis kegagalan senyap yang
 * sudah pernah membungkam notifikasi lima hari (lihat
 * TelegramSetting::sedangTidur()).
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = ['key', 'value', 'type'];

    /**
     * Nilai bawaan + tipe tiap setting.
     *
     * Semua bawaan di sini SENGAJA menyalin perilaku yang berjalan sebelum
     * tabel ini ada, supaya memasang fitur setting tidak diam-diam mengubah
     * apa pun. Yang ingin diubah, diubah dari halaman Setting.
     *
     * [tipe, bawaan, label, kelompok]
     */
    public const DAFTAR = [
        // -------- saklar induk --------
        'notif.master' => ['bool', true, 'Semua notifikasi Telegram', 'induk'],

        // -------- Morning Quest Log (SendMorningBrief) --------
        'notif.morning_brief.enabled' => ['bool', true, 'Morning Quest Log', 'briefing'],
        'notif.morning_brief.time'    => ['time', '08:00', 'Jam Morning Quest Log', 'briefing'],

        // -------- Nightly Summary (SendNightlySummary) --------
        'notif.nightly_summary.enabled' => ['bool', true, 'Nightly Summary', 'briefing'],
        'notif.nightly_summary.time'    => ['time', '21:00', 'Jam Nightly Summary', 'briefing'],

        // -------- Daily Briefing lama (Services/DailyBriefing) --------
        // Ini pesan hitungan ringkas jam 10:00 yang tumpang tindih dengan
        // Morning Quest Log. Bawaannya true supaya perilaku lama terjaga;
        // matikan dari halaman Setting kalau dianggap duplikat.
        'notif.daily_briefing.enabled' => ['bool', true, 'Daily Briefing (ringkas)', 'briefing'],
        'notif.daily_briefing.time'    => ['time', '10:00', 'Jam Daily Briefing', 'briefing'],

        // -------- Reminder (SendTaskReminders) --------
        'notif.task_reminders.enabled'  => ['bool', true, 'Reminder jatuh tempo task', 'reminder'],
        'notif.habit_reminders.enabled' => ['bool', true, 'Reminder habit', 'reminder'],

        // -------- Pulse "sedang apa?" --------
        // 60 menyalin perilaku lama (`(int)date('i') % 60 === 0`).
        'notif.pulse.enabled'          => ['bool', true, 'Pulse "sedang apa?"', 'reminder'],
        'notif.pulse.interval_minutes' => ['int', 60, 'Interval pulse (menit)', 'reminder'],

        // -------- Jam diam --------
        'notif.quiet_hours.enabled' => ['bool', false, 'Jam diam', 'diam'],
        'notif.quiet_hours.start'   => ['time', '22:00', 'Mulai jam diam', 'diam'],
        'notif.quiet_hours.end'     => ['time', '06:00', 'Selesai jam diam', 'diam'],
    ];

    /** Setting yang boleh diubah dari halaman Setting. */
    public static function daftarBisaDiubah(): array
    {
        return self::DAFTAR;
    }

    /**
     * Kunci status: dicatat oleh pengirim, BUKAN diubah user. Dipakai panel
     * status di halaman Setting supaya notifikasi yang mati diam-diam
     * kelihatan tanpa harus membuka log.
     */
    public const KUNCI_STATUS = [
        'status.morning_brief.last_sent_at'   => 'Morning Quest Log',
        'status.nightly_summary.last_sent_at' => 'Nightly Summary',
        'status.daily_briefing.last_sent_at'  => 'Daily Briefing (ringkas)',
        'status.task_reminders.last_sent_at'  => 'Reminder jatuh tempo task',
        'status.habit_reminders.last_sent_at' => 'Reminder habit',
    ];

    /** Cache per-proses, supaya satu request tidak query berulang kali. */
    private static array $cache = [];

    public static function lupakanCache(): void
    {
        self::$cache = [];
    }

    /**
     * Baca satu setting. Tidak pernah melempar exception.
     */
    public static function ambil(string $kunci, $bawaanPaksa = null)
    {
        [$tipe, $bawaan] = self::meta($kunci);

        if ($bawaanPaksa !== null) {
            $bawaan = $bawaanPaksa;
        }

        if (array_key_exists($kunci, self::$cache)) {
            return self::$cache[$kunci];
        }

        try {
            $baris = static::query()->where('key', $kunci)->first();
        } catch (\Throwable $e) {
            // Database tidak bisa dibaca -> pakai bawaan, JANGAN melempar.
            return $bawaan;
        }

        $nilai = $baris === null
            ? $bawaan
            : self::tafsirkan($baris->value, $baris->type ?: $tipe, $bawaan);

        return self::$cache[$kunci] = $nilai;
    }

    /**
     * Tulis satu setting. Mengembalikan true kalau berhasil.
     */
    public static function simpan(string $kunci, $nilai): bool
    {
        [$tipe] = self::meta($kunci);

        try {
            static::query()->updateOrCreate(
                ['key' => $kunci],
                ['value' => self::jadikanTeks($nilai, $tipe), 'type' => $tipe]
            );
        } catch (\Throwable $e) {
            Log::error('[AppSetting] Gagal menyimpan setting.', [
                'kunci' => $kunci,
                'pesan' => $e->getMessage(),
            ]);

            return false;
        }

        unset(self::$cache[$kunci]);

        return true;
    }

    /** Catat jejak waktu status (dipakai pengirim sesudah berhasil kirim). */
    public static function catatStatus(string $kunci): void
    {
        try {
            static::query()->updateOrCreate(
                ['key' => $kunci],
                ['value' => now()->toDateTimeString(), 'type' => 'string']
            );
            unset(self::$cache[$kunci]);
        } catch (\Throwable $e) {
            // Mencatat status tidak boleh menggagalkan pengiriman.
            Log::warning('[AppSetting] Gagal mencatat status.', ['kunci' => $kunci]);
        }
    }

    /** Semua setting yang bisa diubah, lengkap dengan nilai berjalan. */
    public static function semua(): array
    {
        $hasil = [];

        foreach (self::DAFTAR as $kunci => [$tipe, $bawaan, $label, $kelompok]) {
            $hasil[$kunci] = [
                'key'      => $kunci,
                'value'    => self::ambil($kunci),
                'type'     => $tipe,
                'default'  => $bawaan,
                'label'    => $label,
                'group'    => $kelompok,
            ];
        }

        return $hasil;
    }

    // =====================================================================
    // Pembantu
    // =====================================================================

    private static function meta(string $kunci): array
    {
        if (isset(self::DAFTAR[$kunci])) {
            return self::DAFTAR[$kunci];
        }

        // Kunci di luar daftar (mis. status.*) dianggap teks biasa.
        return ['string', null, $kunci, 'lain'];
    }

    private static function tafsirkan(?string $mentah, string $tipe, $bawaan)
    {
        if ($mentah === null) {
            return $bawaan;
        }

        switch ($tipe) {
            case 'bool':
                return filter_var($mentah, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $bawaan;

            case 'int':
                return is_numeric($mentah) ? (int) $mentah : $bawaan;

            case 'time':
                return self::rapikanJam($mentah) ?? $bawaan;

            default:
                return $mentah;
        }
    }

    private static function jadikanTeks($nilai, string $tipe): string
    {
        switch ($tipe) {
            case 'bool':
                return filter_var($nilai, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

            case 'int':
                return (string) (int) $nilai;

            case 'time':
                return self::rapikanJam((string) $nilai) ?? '00:00';

            default:
                return (string) $nilai;
        }
    }

    /** "8:0" / "08:00:00" -> "08:00"; nilai tak masuk akal -> null. */
    public static function rapikanJam(?string $mentah): ?string
    {
        $mentah = trim((string) $mentah);

        if ($mentah === '' || !preg_match('/^(\d{1,2}):(\d{1,2})(?::\d{1,2})?$/', $mentah, $m)) {
            return null;
        }

        $jam = (int) $m[1];
        $menit = (int) $m[2];

        if ($jam > 23 || $menit > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $jam, $menit);
    }

    /**
     * Apakah sekarang sedang di dalam jam diam?
     *
     * Rentang yang melewati tengah malam (22:00 -> 06:00) ditangani dengan
     * membandingkan sebagai string jam, bukan selisih tanggal.
     */
    public static function sedangJamDiam(?Carbon $waktu = null): bool
    {
        if (! self::ambil('notif.quiet_hours.enabled')) {
            return false;
        }

        $mulai = self::ambil('notif.quiet_hours.start');
        $akhir = self::ambil('notif.quiet_hours.end');

        if ($mulai === $akhir) {
            return false;
        }

        $sekarang = ($waktu ?? now())->format('H:i');

        // Rentang normal dalam satu hari, mis. 13:00 -> 15:00.
        if ($mulai < $akhir) {
            return $sekarang >= $mulai && $sekarang < $akhir;
        }

        // Rentang melewati tengah malam, mis. 22:00 -> 06:00.
        return $sekarang >= $mulai || $sekarang < $akhir;
    }
}
