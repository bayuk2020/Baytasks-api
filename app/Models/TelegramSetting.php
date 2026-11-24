<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramSetting extends Model
{
    use HasFactory;

    /**
     * Jam (waktu Asia/Jakarta, lihat config/app.php) saat Sleep Mode
     * membangunkan dirinya sendiri.
     *
     * Dipilih 05:00 karena harus LEBIH AWAL dari semua notifikasi pagi:
     * Morning Quest Log 08:00, daily briefing 10:00. Kalau angka ini
     * digeser melewati 08:00, brief pagi ikut terbungkam lagi.
     */
    public const JAM_BANGUN_OTOMATIS = 5;

    protected $table =
        'telegram_settings';

    public $timestamps =
        false;

    protected $fillable = [
        'user_id',
        'chat_id',
        'enabled',
        'daily_briefing',
        'is_sleeping',
        'sleeping_since',
    ];

    protected $casts = [
        'enabled' =>
            'boolean',

        'daily_briefing' =>
            'boolean',

        'is_sleeping' =>
            'boolean',

        'sleeping_since' =>
            'datetime',
    ];

    /**
     * Apakah notifikasi proaktif sedang dibungkam?
     *
     * JANGAN membaca `is_sleeping` secara langsung di guard mana pun --
     * pakai method ini. Alasannya mahal dipelajari:
     *
     * Sleep Mode cuma bisa dimatikan kalau AI benar-benar memanggil tool
     * `toggle_sleep_mode(status=false)`. Pada 19 November 2026 23:04 user
     * bilang "Selamat tidur" (Sleep Mode menyala), lalu 20 November 08:16
     * user bilang "Selamat pagi" -- AI menjawab ramah TAPI lupa memanggil
     * tool-nya. Akibatnya `is_sleeping` tetap 1 selama lima hari dan SEMUA
     * notifikasi (morning brief, nightly summary, reminder task & habit,
     * daily briefing) diam tanpa satu baris log pun. User sempat bertanya
     * ke bot "Aku udah ucapin selamat pagi kok gaada reminder task nya?"
     * pada 22 November dan tetap tidak ada yang berubah.
     *
     * Jadi Sleep Mode sekarang punya batas waktu: ia berakhir sendiri pada
     * JAM_BANGUN_OTOMATIS berikutnya setelah ia dinyalakan, tidak lagi
     * bergantung pada AI yang harus ingat.
     */
    public function sedangTidur(): bool
    {
        if (! $this->is_sleeping) {
            return false;
        }

        // Tidak ada catatan kapan tidurnya dimulai -- baris lama, atau
        // Sleep Mode dinyalakan oleh versi kode sebelum kolom ini ada.
        // Jangan membungkam selamanya hanya karena jejaknya hilang.
        if (empty($this->sleeping_since)) {
            $this->bangunkan();

            return false;
        }

        try {
            $mulai = Carbon::parse($this->sleeping_since);
        } catch (\Throwable $e) {
            // Nilai tidak bisa dibaca -> perlakukan seperti tanpa jejak.
            $this->bangunkan();

            return false;
        }

        // Jam bangun TERDEKAT sesudah waktu mulai tidur. Tidur jam 23:04
        // berarti bangun 05:00 besok paginya; tidur jam 03:00 berarti
        // bangun 05:00 di hari yang sama.
        $batasBangun = $mulai->copy()->startOfDay()->setTime(self::JAM_BANGUN_OTOMATIS, 0);

        if ($batasBangun->lessThanOrEqualTo($mulai)) {
            $batasBangun->addDay();
        }

        if (now()->greaterThanOrEqualTo($batasBangun)) {
            $this->bangunkan();

            return false;
        }

        return true;
    }

    /**
     * Matikan Sleep Mode dan hapus jejak waktunya.
     */
    public function bangunkan(): void
    {
        $this->forceFill([
            'is_sleeping'    => false,
            'sleeping_since' => null,
        ])->save();
    }

    /**
     * Nyalakan Sleep Mode sambil mencatat kapan ia dimulai -- catatan itu
     * yang dipakai sedangTidur() untuk membangunkan sendiri.
     */
    public function tidurkan(): void
    {
        $this->forceFill([
            'is_sleeping'    => true,
            'sleeping_since' => now(),
        ])->save();
    }
}
