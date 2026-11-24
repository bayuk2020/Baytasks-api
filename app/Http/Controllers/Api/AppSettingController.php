<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Habit;
use App\Models\TelegramSetting;
use App\Services\IzinNotifikasi;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        // Kabari lewat Telegram setiap kali setting benar-benar berubah.
        // Gunanya memastikan jalur Telegram masih hidup pada saat yang sama
        // ketika setting diubah -- kalau pesan ini tidak sampai, berarti
        // masalahnya di jalur kirim, bukan di setting-nya.
        if ($tersimpan !== []) {
            $this->kabariPerubahan($tersimpan);
        }

        return response()->json([
            'success'   => $ditolak === [],
            'tersimpan' => $tersimpan,
            'ditolak'   => $ditolak,
            'status'    => $this->panelStatus(TelegramSetting::first()),
            'sleep'     => $this->keadaanTidur(TelegramSetting::first()),
        ], $ditolak === [] ? 200 : 422);
    }

    /**
     * Mengirim ringkasan perubahan setting ke Telegram.
     *
     * SENGAJA tidak lewat IzinNotifikasi: ini bukan notifikasi proaktif,
     * melainkan jawaban langsung atas tindakan user barusan di halaman
     * Setting. Jadi Sleep Mode dan saklar induk tidak boleh membungkamnya --
     * justru saat mematikan saklar induk, konfirmasinya paling dibutuhkan.
     * Yang tetap dihormati: chat_id harus ada dan integrasi Telegram aktif.
     */
    private function kabariPerubahan(array $tersimpan): void
    {
        $setting = TelegramSetting::first();

        if (! $setting || empty($setting->chat_id) || ! $setting->enabled) {
            return;
        }

        $daftar = AppSetting::daftarBisaDiubah();
        $baris = [];

        foreach ($tersimpan as $kunci => $nilai) {
            $label = $daftar[$kunci][2] ?? $kunci;
            $tipe  = $daftar[$kunci][0] ?? 'string';

            $tampil = match ($tipe) {
                'bool' => $nilai ? 'HIDUP' : 'MATI',
                'time' => (string) $nilai . ' WIB',
                default => (string) $nilai,
            };

            $baris[] = '• <b>' . htmlspecialchars($label, ENT_NOQUOTES, 'UTF-8') . '</b> → ' . $tampil;
        }

        $pesan = "⚙️ <b>Setting diubah</b>\n\n"
            . implode("\n", $baris)
            . "\n\n<i>" . now()->format('d M Y H:i') . ' WIB — dari halaman Setting</i>';

        try {
            (new TelegramService())->sendMessage($setting->chat_id, $pesan);
        } catch (\Throwable $e) {
            // Gagal mengabari TIDAK boleh menggagalkan penyimpanan setting.
            Log::warning('[AppSetting] Gagal mengirim kabar perubahan.', ['pesan' => $e->getMessage()]);
        }
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
     * Mengirim ULANG satu reminder habit sebagai uji coba.
     *
     * Dipilih: habit berikutnya hari ini; kalau sudah tidak ada, habit yang
     * paling baru terlewat. Isinya memakai format dan tombol yang SAMA
     * PERSIS dengan reminder asli (lihat SendTaskReminders "PENGKONDISIAN C"),
     * supaya yang diuji bukan cuma "bisa kirim pesan", tapi seluruh jalur:
     * kirim ke Telegram, tombol inline, sampai webhook yang menerima
     * jawabannya saat tombol ditekan.
     *
     * Reminder asli hanya menyala kalau jam habit pas di jendela 60 detik,
     * jadi tanpa tombol ini satu-satunya cara memastikan adalah menunggu.
     */
    public function testReminder()
    {
        $setting = TelegramSetting::first();

        if (! $setting || empty($setting->chat_id) || ! $setting->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram belum aktif atau chat_id kosong.',
            ], 422);
        }

        $hariIni = now()->toDateString();
        $sekarang = now()->timestamp;

        // NULL di `archived` berarti belum diarsip -- catatan panjangnya ada
        // di SendTaskReminders.
        $habits = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))
            ->whereNotNull('reminder_time')
            ->get()
            ->filter(fn ($h) => ! DB::table('habit_logs')
                ->where('habit_id', $h->id)
                ->where('date', $hariIni)
                ->exists());

        if ($habits->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Semua habit hari ini sudah tercatat — tidak ada yang bisa diuji.',
            ], 422);
        }

        $berikutnya = null;
        $terlewat = null;

        foreach ($habits as $h) {
            $ts = strtotime($hariIni . ' ' . $h->reminder_time);

            if ($ts >= $sekarang) {
                if ($berikutnya === null || $ts < $berikutnya[1]) {
                    $berikutnya = [$h, $ts];
                }
            } elseif ($terlewat === null || $ts > $terlewat[1]) {
                $terlewat = [$h, $ts];
            }
        }

        [$habit, $ts] = $berikutnya ?? $terlewat;
        $jenis = $berikutnya ? 'akan datang' : 'terlewat';
        $jam = Carbon::parse($habit->reminder_time)->format('H:i');

        $pesan = "🧪 <b>UJI COBA REMINDER</b>\n"
            . "<i>Dikirim dari halaman Setting, bukan dari jadwal asli.</i>\n\n"
            . "🔔 <b>Hai Bay, jam sudah menunjukkan pukul {$jam}.</b>\n\n"
            . "Jangan lupa {$habit->emoji} <b>" . htmlspecialchars((string) $habit->title, ENT_NOQUOTES, 'UTF-8') . "</b> "
            . ($habit->description ? '"' . htmlspecialchars((string) $habit->description, ENT_NOQUOTES, 'UTF-8') . '"' : '')
            . "\n\n<i>Habit ini " . ($jenis === 'akan datang'
                ? 'dijadwalkan ' . max(1, (int) round(($ts - $sekarang) / 60)) . ' menit lagi'
                : 'terlewat ' . max(1, (int) round(($sekarang - $ts) / 60)) . ' menit lalu')
            . ". Tombol di bawah berfungsi sungguhan.</i>";

        // Tombol yang sama dengan reminder asli: kalau tombol ini bekerja,
        // berarti webhook (jalur MASUK) juga sehat.
        $tombol = [
            'inline_keyboard' => [[
                ['text' => '✅ Tandai Selesai', 'callback_data' => 'habit_done_direct_' . $habit->id],
                ['text' => '⏳ Tunda 5 Menit', 'callback_data' => 'habit_snooze_5m_' . $habit->id],
            ]],
        ];

        try {
            (new TelegramService())->sendMessage($setting->chat_id, $pesan, [
                'reply_markup' => json_encode($tombol),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram menolak: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => "Reminder uji coba dikirim: {$habit->title} (jam {$jam}, {$jenis}).",
            'habit'   => ['id' => $habit->id, 'title' => $habit->title, 'time' => $jam, 'jenis' => $jenis],
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

    /**
     * Status koneksi — menjawab "apakah jalurnya hidup?" tanpa membuka log.
     *
     * CATATAN PENTING soal ngrok: proyek ini TIDAK memakai ngrok lagi. Sejak
     * pindah ke Cloudflare Tunnel, alamat publiknya TETAP (api.kabyra.my.id)
     * dan tidak pernah berubah-ubah seperti ngrok gratis. Berkas run_ngrok.bat
     * yang masih ada di folder proyek adalah sisa dari pengaturan lama.
     *
     * Yang lebih sering disalahpahami: MENGIRIM reminder tidak butuh tunnel
     * sama sekali. Bot memanggil api.telegram.org dari dalam, jadi arahnya
     * keluar. Tunnel hanya dibutuhkan untuk yang MASUK: webhook (balasan
     * tombol & chat) dan website yang memanggil API ini.
     */
    public function koneksi()
    {
        $hasil = [];

        $token = (string) env('TELEGRAM_BOT_TOKEN', '');

        // ---- jalur keluar: bot -> Telegram ----
        $hasil[] = $this->periksaTelegramKeluar($token);

        // ---- jalur masuk: Telegram -> webhook kita ----
        $hasil[] = $this->periksaWebhook($token);

        // CATATAN: domain publik SENGAJA tidak diperiksa dari sini.
        //
        // `php artisan serve` hanya melayani satu permintaan pada satu waktu.
        // Kalau endpoint ini memanggil https://api.kabyra.my.id, permintaan
        // itu keluar lewat tunnel lalu kembali ke proses PHP yang sama --
        // yang masih sibuk melayani panggilan ini. Hasilnya saling tunggu
        // sampai batas waktu habis, dan tunnel yang sehat dilaporkan mati
        // (terbukti: dari sini 12 detik gagal, dari luar 154 ms berhasil).
        //
        // Pemeriksaan domain publik dilakukan di sisi web, oleh browser yang
        // memang melewati tunnel setiap kali memanggil API ini. Lihat
        // periksaDomainDariBrowser() di routes/settings.tsx.

        // ---- proses lokal ----
        $hasil[] = $this->periksaDenyut(
            'Worker reminder',
            base_path('worker_heartbeat.txt'),
            3,
            'Yang benar-benar mengirim reminder task & habit, tiap ~30 detik.'
        );

        $hasil[] = $this->periksaDenyut(
            'Scheduler Laravel',
            base_path('scheduler_heartbeat.txt'),
            3,
            'Yang menjalankan Morning Quest Log, Nightly Summary, dan Daily Briefing.'
        );

        // ---- bukti worker benar-benar BEKERJA, bukan cuma berdenyut ----
        $hasil[] = $this->periksaLogWorker();

        return response()->json(['koneksi' => $hasil, 'diperiksa' => now()->toDateTimeString()]);
    }

    private function periksaTelegramKeluar(string $token): array
    {
        if ($token === '') {
            return $this->baris('Kirim ke Telegram', false, 'TELEGRAM_BOT_TOKEN belum diisi di .env.');
        }

        try {
            $r = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getMe");
            $d = $r->json();

            return ($d['ok'] ?? false)
                ? $this->baris('Kirim ke Telegram', true, 'Bot @' . ($d['result']['username'] ?? '?') . ' terhubung. Jalur ini TIDAK butuh tunnel.')
                : $this->baris('Kirim ke Telegram', false, 'Telegram menolak token (HTTP ' . $r->status() . ').');
        } catch (\Throwable $e) {
            return $this->baris('Kirim ke Telegram', false, 'Tidak bisa menghubungi api.telegram.org: ' . $e->getMessage());
        }
    }

    private function periksaWebhook(string $token): array
    {
        if ($token === '') {
            return $this->baris('Webhook (balasan tombol)', false, 'Token bot belum diisi.');
        }

        try {
            $info = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getWebhookInfo")->json()['result'] ?? [];
        } catch (\Throwable $e) {
            return $this->baris('Webhook (balasan tombol)', false, 'Gagal memeriksa: ' . $e->getMessage());
        }

        $url = (string) ($info['url'] ?? '');

        if ($url === '') {
            return $this->baris('Webhook (balasan tombol)', false, 'Belum dipasang — tombol di pesan Telegram tidak akan berfungsi.');
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $tertunda = (int) ($info['pending_update_count'] ?? 0);
        $galat = $info['last_error_message'] ?? null;
        $kapan = isset($info['last_error_date']) ? Carbon::createFromTimestamp($info['last_error_date'])->diffForHumans() : null;

        $catatan = "Menunjuk ke {$host}.";

        if (str_contains($url, 'ngrok')) {
            $catatan .= ' Ini alamat ngrok — alamat ngrok gratis berubah tiap restart dan harus dipasang ulang.';
        } else {
            $catatan .= ' Alamat tetap, tidak perlu diganti-ganti.';
        }

        if ($tertunda > 0) {
            $catatan .= " {$tertunda} pesan menunggu diproses.";
        }

        if ($galat) {
            $catatan .= " Galat terakhir ({$kapan}): {$galat}";
        }

        // Galat lama yang sudah lewat sehari tidak lagi dianggap masalah
        // berjalan -- Telegram menyimpannya cukup lama.
        $sehat = $galat === null
            || (isset($info['last_error_date']) && Carbon::createFromTimestamp($info['last_error_date'])->lt(now()->subDay()));

        return $this->baris('Webhook (balasan tombol)', $sehat, $catatan);
    }

    private function periksaDenyut(string $nama, string $berkas, int $batasMenit, string $keterangan): array
    {
        if (! is_file($berkas)) {
            return $this->baris($nama, false, 'Berkas denyut tidak ada — kemungkinan belum pernah jalan.');
        }

        $umur = (int) round((time() - filemtime($berkas)) / 60);

        return $this->baris(
            $nama,
            $umur <= $batasMenit,
            $umur <= $batasMenit
                ? "Denyut terakhir {$umur} menit lalu. {$keterangan}"
                : "Denyut terakhir {$umur} menit lalu — seharusnya di bawah {$batasMenit} menit. {$keterangan}"
        );
    }

    /**
     * Denyut saja tidak cukup.
     *
     * 23-24 Nov 2026 worker berdenyut normal selama 22 jam tanpa mengirim
     * satu pun reminder: berkas log-nya dipegang proses php yang menggantung,
     * dan di cmd sebuah redirect yang gagal membuat perintahnya tidak
     * dijalankan sama sekali. Jadi yang diperiksa di sini adalah bukti php
     * benar-benar menulis sesuatu hari ini.
     */
    private function periksaLogWorker(): array
    {
        $berkas = base_path('worker_run_' . now()->format('Ymd') . '.log');

        if (! is_file($berkas)) {
            return $this->baris(
                'Worker benar-benar bekerja',
                false,
                'Belum ada catatan kerja hari ini (' . basename($berkas) . '). Denyut bisa tetap normal walau php-nya tidak pernah jalan.'
            );
        }

        $umur = (int) round((time() - filemtime($berkas)) / 60);

        return $this->baris(
            'Worker benar-benar bekerja',
            $umur <= 5,
            "Catatan kerja terakhir {$umur} menit lalu di " . basename($berkas) . '.'
        );
    }

    private function baris(string $nama, bool $sehat, string $catatan): array
    {
        return ['nama' => $nama, 'sehat' => $sehat, 'catatan' => $catatan];
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
