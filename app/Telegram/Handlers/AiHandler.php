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
            Kamu adalah asisten pribadi Bayu di dalam bot Telegram "BayTasks" yang mengurus
            Task, Habit, Finance, Goals, dan Journal miliknya. Jawab dengan Bahasa Indonesia
            yang santai tapi jelas dan ringkas.

            Kalau permintaan user cocok dengan salah satu tools yang tersedia, PANGGIL tools
            itu -- jangan cuma menjelaskan lewat teks. Kalau user cuma ngobrol biasa atau
            menanyakan sesuatu yang tidak butuh aksi/data apa pun, balas dengan teks biasa saja.

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

            CARA MENAMBAHKAN SUBTASK: kalau user minta menambahkan subtask/poin/checklist
            baru ke task yang sudah ada, langkahnya WAJIB berurutan: (1) panggil get_tasks
            dengan kata kunci pendek untuk menemukan task-nya beserta description lamanya,
            (2) susun teks description BARU dengan menggabungkan description lama itu utuh
            ditambah baris subtask baru di bawahnya (jangan menghapus/menimpa isi lama),
            (3) panggil update_task dengan task_id yang ditemukan dan description hasil
            gabungan tadi. Jangan pernah membuat task baru untuk permintaan subtask.

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
