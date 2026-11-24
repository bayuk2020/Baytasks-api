<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\TelegramSetting;
use App\Services\IzinNotifikasi;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint halaman Setting.
 *
 * Tiga hal yang disediakan:
 *   1. baca & tulis setting notifikasi (app_settings + telegram_settings);
 *   2. PANEL STATUS -- kapan tiap notifikasi terakhir benar-benar terkirim,
 *      dan kalau sekarang tidak akan terkirim, APA ALASANNYA. Ini jawaban
 *      langsung atas kejadian 19-24 November 2026: notifikasi diam lima hari
 *      dan tidak ada satu pun tempat di aplikasi yang bisa memberi tahu;
 *   3. tombol kirim manual, supaya brief bisa dipicu tanpa menunggu jadwal.
 */
class AppSettingController extends Controller
{
    /** Pengirim yang ditampilkan di panel status, beserta saklarnya. */
    private const PENGIRIM = [
        'morning_brief' => [
            'label'     => 'Morning Quest Log',
            'enabled'   => 'notif.morning_brief.enabled',
            'time'      => 'notif.morning_brief.time',
            'status'    => 'status.morning_brief.last_sent_at',
            'harian'    => true,
        ],
        'nightly_summary' => [
            'label'     => 'Nightly Summary',
            'enabled'   => 'notif.nightly_summary.enabled',
            'time'      => 'notif.nightly_summary.time',
            'status'    => 'status.nightly_summary.last_sent_at',
            'harian'    => true,
        ],
        'daily_briefing' => [
            'label'     => 'Daily Briefing (ringkas)',
            'enabled'   => 'notif.daily_briefing.enabled',
            'time'      => 'notif.daily_briefing.time',
            'status'    => 'status.daily_briefing.last_sent_at',
            'harian'    => true,
        ],
        'task_reminders' => [
            'label'     => 'Reminder jatuh tempo task',
            'enabled'   => 'notif.task_reminders.enabled',
            'time'      => null,
            'status'    => 'status.task_reminders.last_sent_at',
            'harian'    => false,
        ],
        'habit_reminders' => [
            'label'     => 'Reminder habit',
            'enabled'   => 'notif.habit_reminders.enabled',
            'time'      => null,
            'status'    => 'status.habit_reminders.last_sent_at',
            'harian'    => false,
        ],
    ];

    public function index()
    {
        AppSetting::lupakanCache();

        $setting = TelegramSetting::first();

        return response()->json([
            'settings' => array_values(AppSetting::semua()),
            'telegram' => [
                'chat_id' => $setting->chat_id ?? '',
                'enabled' => (bool) ($setting->enabled ?? false),
            ],
            'sleep' => $this->keadaanTidur($setting),
            'status' => $this->panelStatus($setting),
        ]);
    }

    /**
     * Menyimpan sebagian setting sekaligus.
     *
     * Bentuk payload: { "settings": { "notif.master": false, ... },
     *                   "telegram": { "chat_id": "...", "enabled": true } }
     *
     * Hanya kunci yang terdaftar di AppSetting::DAFTAR yang diterima, jadi
     * kunci status.* tidak bisa dipalsukan dari luar.
     */
    public function update(Request $request)
    {
        $diminta = (array) $request->input('settings', []);
        $daftar  = AppSetting::daftarBisaDiubah();

        $tersimpan = [];
        $ditolak = [];

        foreach ($diminta as $kunci => $nilai) {
            if (! isset($daftar[$kunci])) {
                $ditolak[$kunci] = 'Kunci tidak dikenal.';
                continue;
            }

            [$tipe] = $daftar[$kunci];

            if ($tipe === 'time' && AppSetting::rapikanJam((string) $nilai) === null) {
                $ditolak[$kunci] = 'Format jam harus HH:MM.';
                continue;
            }

            if ($tipe === 'int' && ! is_numeric($nilai)) {
                $ditolak[$kunci] = 'Harus berupa angka.';
                continue;
            }

            // Interval pulse 0 atau negatif akan membuat modulo di
            // SendTaskReminders membagi dengan nol / cocok tiap menit.
            if ($kunci === 'notif.pulse.interval_minutes' && ((int) $nilai < 1 || (int) $nilai > 1440)) {
                $ditolak[$kunci] = 'Interval pulse harus 1-1440 menit.';
                continue;
            }

            if (AppSetting::simpan($kunci, $nilai)) {
                $tersimpan[$kunci] = AppSetting::ambil($kunci);
            } else {
                $ditolak[$kunci] = 'Gagal menyimpan ke database.';
            }
        }

        // Kolom lama `telegram_settings.daily_briefing` ikut disamakan supaya
        // tidak ada dua sumber kebenaran yang saling bertentangan. Pembacanya
        // sudah dipindah ke app_settings (lihat Services/DailyBriefing).
        $setting = TelegramSetting::first();

        if ($setting && array_key_exists('notif.daily_briefing.enabled', $tersimpan)) {
            $setting->forceFill([
                'daily_briefing' => (bool) $tersimpan['notif.daily_briefing.enabled'],
            ])->save();
        }

        // ---- bagian telegram_settings ----
        $telegram = (array) $request->input('telegram', []);

        if ($setting && $telegram !== []) {
            $ubah = [];

            if (array_key_exists('chat_id', $telegram)) {
                $chatId = trim((string) $telegram['chat_id']);

                if ($chatId !== '' && ! preg_match('/^-?\d{5,32}$/', $chatId)) {
                    $ditolak['telegram.chat_id'] = 'Chat ID harus angka (boleh diawali -).';
                } else {
                    $ubah['chat_id'] = $chatId;
                }
            }

            if (array_key_exists('enabled', $telegram)) {
                $ubah['enabled'] = (bool) filter_var($telegram['enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            if ($ubah !== []) {
                // JANGAN pakai TelegramSettingController@save di sini: method
                // itu mengirim pesan "Koneksi BayTasks Berhasil!" ke Telegram
                // setiap kali dipanggil. Halaman Setting menyimpan otomatis,
                // jadi menyimpan lewat sana berarti membanjiri chat.
                $setting->forceFill($ubah)->save();
            }
        }

        AppSetting::lupakanCache();

        return response()->json([
            'success'   => $ditolak === [],
            'tersimpan' => $tersimpan,
            'ditolak'   => $ditolak,
            'status'    => $this->panelStatus(TelegramSetting::first()),
            'sleep'     => $this->keadaanTidur(TelegramSetting::first()),
        ], $ditolak === [] ? 200 : 422);
    }

    /**
     * Nyalakan / matikan Sleep Mode dari web.
     *
     * Sebelum ini Sleep Mode HANYA bisa diubah dengan menyuruh bot lewat
     * chat, dan kalau AI lupa memanggil tool-nya, tidak ada jalan keluar
     * selain mengedit database. Itulah yang terjadi 19-24 November 2026.
     */
    public function sleep(Request $request)
    {
        $aktif = (bool) filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);

        $setting = TelegramSetting::first();

        if (! $setting) {
            return response()->json(['success' => false, 'message' => 'Baris telegram_settings belum ada.'], 404);
        }

        $aktif ? $setting->tidurkan() : $setting->bangunkan();

        return response()->json([
            'success' => true,
            'sleep'   => $this->keadaanTidur(TelegramSetting::first()),
        ]);
    }

    /**
     * Kirim sekarang, tanpa menunggu jadwal.
     *
     * Command dijalankan DI DALAM proses ini (Artisan::call), bukan sebagai
     * proses anak. Penting di mesin ini: membuat proses baru sering gagal,
     * dan setiap kegagalan menulis stacktrace panjang ke log.
     */
    public function sendNow(Request $request, string $jenis)
    {
        $peta = [
            'morning-brief'   => 'baytasks:morning-brief',
            'nightly-summary' => 'baytasks:nightly-summary',
        ];

        if ($jenis === 'test') {
            return $this->kirimUjiCoba();
        }

        if (! isset($peta[$jenis])) {
            return response()->json(['success' => false, 'message' => 'Jenis tidak dikenal.'], 404);
        }

        try {
            $kode = Artisan::call($peta[$jenis]);
            $keluaran = trim(Artisan::output());
        } catch (\Throwable $e) {
            Log::error('[AppSetting] Gagal kirim manual.', ['jenis' => $jenis, 'pesan' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Command gagal dijalankan: ' . $e->getMessage(),
            ], 500);
        }

        // Command memulangkan 0 juga ketika ia MELEWATI pengiriman (mis.
        // saklarnya mati). Jadi keberhasilan diukur dari jejak waktu status,
        // bukan dari exit code.
        $jejak = AppSetting::ambil('status.' . str_replace('-', '_', $jenis) . '.last_sent_at');
        $baruSaja = $jejak && Carbon::parse($jejak)->greaterThan(now()->subMinute());

        return response()->json([
            'success'  => $baruSaja,
            'message'  => $baruSaja
                ? 'Terkirim ke Telegram.'
                : 'Command jalan tapi tidak mengirim apa pun. Alasannya ada di keluaran di bawah.',
            'exit'     => $kode,
            'keluaran' => $keluaran,
            'status'   => $this->panelStatus(TelegramSetting::first()),
        ]);
    }

    /**
     * Pesan uji coba -- pengganti tombol "Send test message" yang lama.
     *
     * Yang lama tidak mengirim tes apa pun: ia memanggil endpoint save, lalu
     * menampilkan toast hijau tanpa melihat hasilnya, sementara pesan yang
     * sampai ke Telegram datang dari efek samping save. Yang ini benar-benar
     * mengirim dan melaporkan hasil sebenarnya.
     */
    private function kirimUjiCoba()
    {
        $setting = TelegramSetting::first();

        if (! $setting || empty($setting->chat_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Chat ID masih kosong.',
            ], 422);
        }

        $pesan = "🔧 <b>Uji coba dari halaman Setting</b>\n\n"
            . 'Dikirim ' . now()->format('d M Y H:i') . " WIB.\n"
            . 'Kalau pesan ini sampai, jalur Telegram sehat.';

        try {
            (new TelegramService())->sendMessage($setting->chat_id, $pesan);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram menolak: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pesan uji coba dikirim.',
        ]);
    }

    // =====================================================================
    // Pembantu
    // =====================================================================

    private function keadaanTidur(?TelegramSetting $setting): array
    {
        $jamBangun = sprintf('%02d:00', TelegramSetting::JAM_BANGUN_OTOMATIS);

        if (! $setting) {
            return ['active' => false, 'since' => null, 'auto_wake_at' => $jamBangun, 'wake_time' => null];
        }

        // Memanggil sedangTidur() sekaligus membereskan Sleep Mode yang sudah
        // lewat jam bangunnya, jadi yang dilaporkan ke web selalu keadaan
        // yang benar-benar berlaku -- bukan isi kolom mentahnya.
        $aktif = $setting->sedangTidur();
        $setting->refresh();

        $bangunPada = null;

        if ($aktif && $setting->sleeping_since) {
            $mulai = Carbon::parse($setting->sleeping_since);
            $bangunPada = $mulai->copy()->startOfDay()->setTime(TelegramSetting::JAM_BANGUN_OTOMATIS, 0);

            if ($bangunPada->lessThanOrEqualTo($mulai)) {
                $bangunPada->addDay();
            }
        }

        return [
            'active'       => $aktif,
            'since'        => $setting->sleeping_since ? Carbon::parse($setting->sleeping_since)->toDateTimeString() : null,
            'auto_wake_at' => $jamBangun,
            'wake_time'    => $bangunPada?->toDateTimeString(),
        ];
    }

    /**
     * Untuk tiap pengirim: kapan terakhir terkirim, apakah tertinggal, dan
     * kalau sekarang tidak akan terkirim, apa alasannya.
     */
    private function panelStatus(?TelegramSetting $setting): array
    {
        $hasil = [];

        foreach (self::PENGIRIM as $kode => $info) {
            $izin = IzinNotifikasi::periksa($info['enabled'], ! $info['harian'], $setting);

            $terakhir = AppSetting::ambil($info['status']);
            $jam = $info['time'] ? AppSetting::ambil($info['time']) : null;

            $hasil[] = [
                'key'            => $kode,
                'label'          => $info['label'],
                'enabled'        => (bool) AppSetting::ambil($info['enabled']),
                'time'           => $jam,
                'last_sent_at'   => $terakhir ?: null,
                'akan_terkirim'  => $izin['boleh'],
                'alasan'         => $izin['alasan'],
                'tertinggal'     => $this->tertinggal($info, $jam, $terakhir),
            ];
        }

        return $hasil;
    }

    /**
     * Notifikasi harian dianggap TERTINGGAL kalau jam kirimnya hari ini sudah
     * lewat tapi jejak terakhirnya bukan hari ini. Inilah penanda yang dulu
     * tidak ada -- lima hari brief hilang tanpa satu pun indikator.
     */
    private function tertinggal(array $info, ?string $jam, ?string $terakhir): bool
    {
        if (! $info['harian'] || ! $jam) {
            return false;
        }

        if (! AppSetting::ambil($info['enabled'])) {
            return false;
        }

        [$j, $m] = array_pad(explode(':', $jam), 2, '0');
        $jadwalHariIni = now()->copy()->startOfDay()->setTime((int) $j, (int) $m);

        if (now()->lessThan($jadwalHariIni)) {
            return false;
        }

        if (! $terakhir) {
            return true;
        }

        try {
            return Carbon::parse($terakhir)->lessThan($jadwalHariIni);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
