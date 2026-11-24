<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AppSetting;
use App\Models\Board;
use App\Services\IzinNotifikasi;
use App\Models\Habit;
use App\Models\Task;
use App\Models\TelegramSetting;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendMorningBrief extends Command
{
    // --dry-run: menyusun pesan lengkap lalu MENCETAKNYA ke layar tanpa
    // mengirim apa pun ke Telegram. Dipakai untuk memeriksa tata letak
    // (pengelompokan board, penanda baris) tanpa membanjiri chat.
    protected $signature = 'baytasks:morning-brief {--dry-run : Cetak pesan ke layar, jangan kirim ke Telegram}';
    protected $description = 'Kirim Morning Quest Log jam 07:00 WIB';

    public function handle()
    {
        Log::info('[baytasks:morning-brief] START');

        $kering = (bool) $this->option('dry-run');

        $setting = TelegramSetting::first();
        if (!$setting || !$setting->chat_id) {
            Log::warning('[baytasks:morning-brief] ABORT: telegram_settings tidak ada / chat_id kosong');
            $this->warn('ABORT: chat_id Telegram belum diatur.');
            return 0;
        }

        // Semua syarat kirim diperiksa di satu tempat (saklar induk, saklar
        // Morning Quest Log, Sleep Mode) -- lihat Services/IzinNotifikasi.
        // Jam diam SENGAJA tidak dihormati di sini: jam brief dipilih sendiri
        // oleh user di halaman Setting, jadi tidak perlu ditawar lagi.
        //
        // --dry-run menembus semua syarat itu karena tidak mengirim apa pun;
        // gunanya memang memeriksa tampilan kapan saja.
        if (!$kering) {
            $izin = IzinNotifikasi::periksa('notif.morning_brief.enabled', false, $setting);

            if (!$izin['boleh']) {
                Log::info('[baytasks:morning-brief] SKIP: ' . $izin['alasan']);

                // Dicetak ke stdout dengan sengaja. Dulu command ini keluar
                // tanpa mencetak apa pun, jadi morning_brief.log tidak ikut
                // tersentuh dan sekilas tampak seperti command tidak pernah
                // jalan sama sekali -- jejak palsu yang sempat menyesatkan.
                $this->info('SKIP: ' . $izin['alasan']);

                return 0;
            }
        }

        // 1. Ambil Data Habit
        // NULL di kolom `archived` harus dianggap belum diarsip -- lihat catatan
        // panjang soal bug ini di SendTaskReminders.php.
        $habits = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))
            ->where('frequency', 'daily')
            ->orderBy('reminder_time', 'asc')
            ->get();

        // 2. Ambil Data Task & Subtask
        // Catatan: Sorting priority diubah jadi raw agar urut (high > med > low)
        $tasks = Task::with(['subtasks' => function ($q) {
            $q->where('done', false)->orderBy('position', 'asc');
        }])
            ->whereNull('completed_at')
            // Task yang sudah digeser ke kolom "Done" di Kanban tidak boleh
            // ikut terdaftar lagi. Saringan `completed_at` saja tidak cukup:
            // ada task lama (id 43, 50, 95, 97) dengan column_key='done' tapi
            // completed_at masih NULL -- warisan dari sebelum TaskController
            // menjadikan column_key sebagai sumber kebenaran (lihat catatan
            // panjang di TaskController@update). Dulu keempatnya tetap muncul
            // di Morning Quest Log dan membuat angka "Task" kelebihan 4.
            //
            // column_key NULL sengaja dianggap BELUM done: kolomnya nullable
            // (default 'todo'), jadi baris yang nilainya kosong jangan sampai
            // ikut terbuang.
            ->where(fn ($q) => $q->where('column_key', '!=', 'done')->orWhereNull('column_key'))
            ->orderByRaw("FIELD(priority, 'high', 'med', 'low')")
            ->orderBy('due_at', 'asc')
            ->orderBy('position', 'asc')
            ->get();

        // 2b. Daftar board, dipakai untuk mengelompokkan task di bawah.
        // Urutan grup mengikuti kolom `position` supaya susunannya sama dengan
        // tampilan board di web (Kerjaan -> BayTasks Launch -> Personal), bukan
        // urutan prioritas task. Diambil sekali di sini, bukan lewat relasi per
        // task, biar tidak jadi N+1 query.
        $boards = Board::orderBy('position', 'asc')->orderBy('id', 'asc')->get();

        // 3. Kalkulasi Target
        $targetHabit = $habits->count();
        $targetTask = $tasks->count();
        $targetSubtask = 0;

        // 4. Susun Pesan
        $msg = "🌅 <b>MORNING QUEST LOG</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $msg .= "Semangat pagi! ☀️\n";
        $msg .= "Hari ini adalah kesempatan baru untuk menambah XP dan menjaga streak.\n\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Habit
        // Penanda "☐" (U+2610) dibuang: di sebagian perangkat Telegram karakter
        // itu tidak punya glyph, jadi muncul sebagai kotak kosong / tanda tanya.
        // Emoji habit sendiri sudah berfungsi sebagai penanda baris.
        $msg .= "🎯 <b>MAIN QUEST (Habit)</b>\n";
        if ($targetHabit == 0) $msg .= "<i>Tidak ada habit hari ini.</i>\n";
        foreach ($habits as $habit) {
            $emoji = trim((string) $habit->emoji);
            $msg .= ($emoji !== '' ? "{$emoji} " : '• ') . $this->teks($habit->title) . "\n";
        }
        $msg .= "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Task & Subtask -- DIKELOMPOKKAN PER BOARD.
        // Sebelumnya semua task dicetak dalam satu daftar panjang, jadi task
        // dari board berbeda (Kerjaan / BayTasks Launch / Personal) tercampur
        // dan tidak ada cara membedakannya di layar Telegram.
        $msg .= "📌 <b>TASK</b>\n";
        if ($targetTask == 0) $msg .= "\n<i>Tidak ada task tersisa. Kamu bebas!</i>\n";

        // Kunci grup dinormalkan ke int. Task tanpa board (board_id NULL) akan
        // jadi kunci 0 -- kalau dibiarkan null, Collection mengubahnya jadi ''
        // dan grup itu hilang dari perbandingan di bawah.
        $perBoard = $tasks->groupBy(fn ($task) => (int) ($task->board_id ?? 0));

        // Board yang terdaftar dicetak dulu sesuai `position`; sisanya (task
        // tanpa board, atau board_id yang barisnya sudah terhapus) menyusul.
        $urutanBoard = $boards->pluck('id')->map(fn ($id) => (int) $id)->all();
        $boardSisa = $perBoard->keys()
            ->reject(fn ($id) => in_array((int) $id, $urutanBoard, true))
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        foreach (array_merge($urutanBoard, $boardSisa) as $boardId) {
            $daftar = $perBoard->get($boardId);

            // Board yang tidak punya task tersisa tidak dicetak sama sekali,
            // supaya tidak ada judul kosong menggantung.
            if (!$daftar || $daftar->isEmpty()) {
                continue;
            }

            $board = $boards->firstWhere('id', $boardId);
            $emojiBoard = trim((string) ($board->emoji ?? ''));
            $namaBoard = $board->name ?? 'Tanpa Board';

            $msg .= "\n" . ($emojiBoard !== '' ? "{$emojiBoard} " : '📁 ')
                . '<b>' . $this->teks($namaBoard) . '</b> (' . $daftar->count() . ")\n";

            foreach ($daftar as $task) {
                $msg .= '• ' . $this->teks($task->title) . "\n";
                foreach ($task->subtasks as $subtask) {
                    $msg .= '   - ' . $this->teks($subtask->title) . "\n";
                    $targetSubtask++; // Hitung total subtask
                }
            }
        }
        $msg .= "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Blok Target
        $msg .= "🎁 <b>TARGET HARI INI</b>\n\n";
        $msg .= "Habit : $targetHabit\n";
        $msg .= "Task : $targetTask\n";
        $msg .= "Subtask : $targetSubtask\n\n";
        $msg .= "Semoga harimu produktif! 🚀";

        if ($kering) {
            $this->line($msg);
            $this->newLine();
            $this->info('[DRY RUN] Pesan di atas TIDAK dikirim ke Telegram.');
            return 0;
        }

        // Kirim Telegram
        $telegram = new TelegramService();
        $telegram->sendMessage($setting->chat_id, $msg);

        // Jejak waktu untuk panel status di halaman Setting. Tanpa ini,
        // notifikasi yang mati diam-diam baru ketahuan setelah berhari-hari.
        AppSetting::catatStatus('status.morning_brief.last_sent_at');

        Log::info('[baytasks:morning-brief] DONE -- terkirim.', ['habit_count' => $targetHabit, 'task_count' => $targetTask]);
        $this->info('Morning brief sent successfully!');
        return 0;
    }

    /**
     * Membersihkan judul buatan user sebelum disisipkan ke pesan.
     *
     * Morning Brief dikirim dengan parse_mode=HTML (lihat TelegramService),
     * jadi judul yang memuat < > & bisa membuat Telegram menolak SELURUH pesan.
     * Saat ini ada 4 judul seperti itu di database, mis. "Menginstall printer
     * Canon G2237 ... Mbak Tya & Mas Kurnia".
     *
     * ENT_NOQUOTES dipakai dengan sengaja, bukan default e()/ENT_QUOTES:
     * Telegram hanya mewajibkan < > & di-escape, sedangkan tanda kutip harus
     * dibiarkan utuh supaya judul seperti "Al-Ma'tsurat" dan "Baca Al-Waqi'ah"
     * tidak tampil jadi "Al-Ma&#039;tsurat".
     */
    private function teks(?string $nilai): string
    {
        return htmlspecialchars((string) $nilai, ENT_NOQUOTES, 'UTF-8');
    }
}
