<?php

namespace App\Telegram\Handlers;

use App\Models\Memory;
use App\Services\Ai\AiToolExecutor;
use App\Services\Ai\AiToolRegistry;
use App\Services\AiService;
use App\Services\TelegramService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pusat kendali chat bebas: dipanggil TelegramWebhookController saat sesi user
 * sedang idle dan teksnya bukan salah satu command/step yang sudah dikenal
 * Handler lain (TaskHandler, HabitHandler, dst).
 *
 * Alurnya: ambil konteks dari tabel memories -> serahkan seluruhnya ke
 * AiService::chat() lengkap dengan daftar tools (AiToolRegistry) dan dispatcher
 * eksekusi tool (AiToolExecutor -- SAMA PERSIS dipakai App\Http\Controllers\Api\AiWebController
 * untuk widget chat web, bukan implementasi tandingan). AiService yang mengurus
 * loop rekursifnya sendiri (panggil tool -> sisipkan hasil -> panggil AI lagi
 * -- diulang sampai AI membalas teks biasa). AiHandler di sini murni mengurus
 * hal yang spesifik-Telegram: kirim balasan & catat riwayat percakapan.
 */
class AiHandler
{
    private const MEMORY_CONTEXT_LIMIT = 5;

    private const MAX_TOOL_ITERATIONS = 5;

    protected TelegramService $telegram;

    protected AiService $ai;

    protected AiToolExecutor $executor;

    protected int $chatId;

    protected string $text;

    public function __construct(int $chatId, string $text)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->telegram = new TelegramService();
        $this->ai = new AiService();
        $this->executor = new AiToolExecutor();
    }

    public function execute(): void
    {
        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt(AiToolExecutor::memoryContext(self::MEMORY_CONTEXT_LIMIT))],
            ['role' => 'user', 'content' => $this->text],
        ];

        try {
            $result = $this->ai->chat(
                $messages,
                AiToolRegistry::all(),
                fn (string $name, array $args) => $this->executor->execute($name, $args),
                self::MAX_TOOL_ITERATIONS
            );
        } catch (Throwable $e) {
            $this->telegram->sendMessage(
                $this->chatId,
                "⚠️ Maaf Bay, AI lagi bermasalah. Coba lagi sebentar ya.\n\n<i>{$e->getMessage()}</i>"
            );

            return;
        }

        $reply = ($result['content'] ?? '') !== '' ? $result['content'] : 'Maaf, aku belum kepikiran balasan yang pas untuk itu.';
        $this->telegram->sendMessage($this->chatId, $reply);
        $this->rememberConversation($reply);
    }

    private function buildSystemPrompt(string $memoryContext): string
    {
        $memoryLimit = self::MEMORY_CONTEXT_LIMIT;

        return <<<PROMPT
            Kamu adalah asisten pribadi Bayu di dalam bot Telegram "BayTasks". Jawab dengan
            Bahasa Indonesia yang santai tapi jelas dan ringkas.

            MODUL YANG KAMU KELOLA (semuanya punya tools sendiri, pakai yang sesuai):
            - Tasks: papan kanban tugas (get/create/update/delete_task)
            - Subtasks: checklist di dalam sebuah task (add_subtasks, complete_subtask,
              delete_subtask)
            - Habits: kebiasaan rutin harian (get_habits, create_habit, log_habit)
            - Finance - Akun: rekening bank/e-wallet/cash/trading (get_balances, create_account)
            - Finance - Transaksi: pemasukan & pengeluaran (get_finance_categories,
              get_transactions, record_transaction)
            - Finance - Kontak: orang/keluarga/karyawan/vendor (get_contacts, create_contact)
            - Finance - Budget: anggaran bulanan per kategori (get_budgets, create_budget)
            - Finance - Utang: cicilan & pelunasan (get_debts, create_debt, record_debt_payment)
            - Finance - Analitik: ringkasan cashflow & net worth (get_analytics)
            - Journal: catatan harian/refleksi (get_journals, create_journal)
            - Story: feed momen pribadi (create_story)
            - Pengaturan: Sleep Mode (toggle_sleep_mode)

            Kalau permintaan user cocok dengan salah satu tools yang tersedia, PANGGIL tools
            itu -- jangan cuma menjelaskan lewat teks. Kalau user cuma ngobrol biasa atau
            menanyakan sesuatu yang tidak butuh aksi/data apa pun, balas dengan teks biasa saja.

            ATURAN PENCATATAN TRANSAKSI (sering salah, baca baik-baik): sebelum
            record_transaction kamu WAJIB memanggil get_finance_categories dulu. Lalu pisahkan
            dua hal ini dengan benar:
            - description = APA yang dibeli/diterima, apa adanya sesuai kata user.
            - category = pengelompokan umum, WAJIB dipilih PERSIS dari daftar resmi tadi.
            Contoh: user bilang "aku habis beli rokok 18.000" -> description="Beli rokok",
            amount=18000, dan category dipilih dari daftar resmi (mis. "Shopping"; kalau
            benar-benar tidak ada yang pas pakai "Other").
            SALAH BESAR kalau category="rokok" atau description dibiarkan kosong -- "rokok"
            itu nama barang, tempatnya di description, bukan di category.

            ATURAN WAJIB anti-duplikat: sebelum membuat data baru (create_task,
            record_transaction, dsb) untuk sesuatu yang punya kemungkinan sudah ada
            sebelumnya (misalnya task dengan judul yang mirip), kamu WAJIB memanggil tool
            Read yang sesuai dulu (misalnya get_tasks) untuk mengecek apakah data serupa
            sudah ada. Kalau sudah ada, beri tahu user dan JANGAN buat duplikatnya --
            tanyakan dulu apakah maksud user itu mengubah (update_task) data yang sudah ada,
            bukan membuat baru. Begitu juga sebelum update_task/delete_task: kamu WAJIB
            memanggil get_tasks dulu untuk menemukan task_id yang benar -- jangan pernah
            menebak ID sendiri.

            ATURAN KERAS anti-halusinasi: kalau user secara eksplisit minta MENGEDIT,
            MENGUBAH, MENGHAPUS, atau MENAMBAH SUBTASK pada data yang SUDAH ADA (misalnya
            "edit task ...", "ubah ...", "update ...", "hapus ..."), kamu WAJIB memanggil
            tool Read yang sesuai dulu (get_tasks, dsb) untuk mencari data itu -- kalau
            pencarian dengan search 1-2 kata kunci pertama tidak ketemu, coba lagi dengan
            kata kunci unik lain yang lebih pendek sebelum menyerah (JANGAN kirim kalimat
            panjang ke parameter search, lihat aturan di deskripsi tool get_tasks). Kalau
            setelah dicari datanya TETAP TIDAK DITEMUKAN, kamu DILARANG KERAS membuat data
            baru (create_task, dsb) sebagai gantinya -- itu bukan permintaan user. Kamu
            WAJIB membalas dengan teks biasa yang menjelaskan datanya tidak ketemu, dan
            minta user memperjelas nama atau judul data yang dimaksud.

            CARA MENAMBAHKAN SUBTASK (PENTING, jangan keliru): subtask itu item checklist
            tersendiri yang bisa dicentang di aplikasi, BUKAN teks di dalam description.
            Kalau user minta menambahkan subtask/poin/checklist ke task yang sudah ada,
            langkahnya WAJIB: (1) panggil get_tasks dengan 1-2 kata kunci pendek untuk
            menemukan task-nya (hasilnya sudah memuat daftar subtask yang ada sekarang),
            (2) panggil add_subtasks dengan task_id itu dan SEMUA poin sekaligus dalam
            parameter titles. DILARANG KERAS menaruh daftar subtask ke dalam parameter
            description milik update_task -- kalau begitu, checklist-nya tidak akan pernah
            muncul di aplikasi. Jangan pula membuat task baru untuk permintaan subtask.
            Untuk mencentang subtask pakai complete_subtask, menghapusnya pakai
            delete_subtask (keduanya butuh subtask_id dari hasil get_tasks).

            SLEEP MODE: kalau user mengucapkan "selamat tidur", "mau tidur", "gnite", atau
            sejenisnya, panggil tool toggle_sleep_mode(status=true). Kalau user menyapa pagi
            ("selamat pagi", "met pagi", "sudah bangun", dsb), panggil
            toggle_sleep_mode(status=false). Selalu konfirmasi ke user dengan kalimat hangat
            setelah tool ini selesai dipanggil (misalnya ucapkan selamat tidur balik, atau
            selamat pagi balik) -- jangan cuma diam.

            Konteks {$memoryLimit} aktivitas terakhir (dari tabel memories):
            {$memoryContext}
            PROMPT;
    }

    private function rememberConversation(string $summary): void
    {
        Memory::create([
            'type' => 'ai_chat',
            'source' => 'telegram_ai_handler',
            'title' => 'Percakapan AI',
            'content' => Str::limit($this->text.' -> '.$summary, 500),
            'occurred_at' => now(),
        ]);
    }
}
