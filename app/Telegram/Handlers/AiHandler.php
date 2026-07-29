<?php

namespace App\Telegram\Handlers;

use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\Finance\TransactionController;
use App\Models\Account;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Memory;
use App\Services\Ai\AiToolRegistry;
use App\Services\AiService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pusat kendali chat bebas: dipanggil TelegramWebhookController saat sesi user
 * sedang idle dan teksnya bukan salah satu command/step yang sudah dikenal
 * Handler lain (TaskHandler, HabitHandler, dst).
 *
 * Alurnya: ambil konteks dari tabel memories -> kirim ke AiService::askAi()
 * dengan daftar tools dari AiToolRegistry -> kalau AI memilih memanggil salah
 * satu tool, eksekusi aksinya di sini; kalau cuma teks biasa, balas apa adanya.
 */
class AiHandler
{
    private const MEMORY_CONTEXT_LIMIT = 5;

    protected TelegramService $telegram;

    protected AiService $ai;

    protected int $chatId;

    protected string $text;

    public function __construct(int $chatId, string $text)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->telegram = new TelegramService();
        $this->ai = new AiService();
    }

    public function execute(): void
    {
        try {
            $result = $this->ai->askAi(
                $this->buildSystemPrompt($this->buildMemoryContext()),
                $this->text,
                AiToolRegistry::all()
            );
        } catch (Throwable $e) {
            $this->telegram->sendMessage(
                $this->chatId,
                "⚠️ Maaf Bay, semua provider AI lagi bermasalah. Coba lagi sebentar ya.\n\n<i>{$e->getMessage()}</i>"
            );

            return;
        }

        if ($result['type'] === 'function_call') {
            $this->handleFunctionCall($result['function']);

            return;
        }

        $reply = $result['content'] !== '' ? $result['content'] : 'Maaf, aku belum kepikiran balasan yang pas untuk itu.';
        $this->telegram->sendMessage($this->chatId, $reply);
        $this->rememberConversation($reply);
    }

    /**
     * Ambil 5 aktivitas terakhir dari tabel memories sebagai konteks tambahan,
     * supaya bot "ingat" percakapan/aktivitas sebelumnya secara kasar.
     */
    private function buildMemoryContext(): string
    {
        $memories = Memory::orderByDesc('occurred_at')
            ->limit(self::MEMORY_CONTEXT_LIMIT)
            ->get();

        if ($memories->isEmpty()) {
            return '(Belum ada riwayat aktivitas sebelumnya.)';
        }

        return $memories
            ->reverse()
            ->map(fn (Memory $m) => "- [{$m->occurred_at}] {$m->title}: {$m->content}")
            ->implode("\n");
    }

    private function buildSystemPrompt(string $memoryContext): string
    {
        $memoryLimit = self::MEMORY_CONTEXT_LIMIT;

        return <<<PROMPT
            Kamu adalah asisten pribadi Bayu di dalam bot Telegram "BayTasks" yang mengurus
            Task, Habit, Finance, Goals, dan Journal miliknya. Jawab dengan Bahasa Indonesia
            yang santai tapi jelas dan ringkas.

            Kalau permintaan user cocok dengan salah satu tools yang tersedia (bikin task,
            catat habit selesai, catat transaksi keuangan, dst), PANGGIL tools itu — jangan
            cuma menjelaskan lewat teks. Kalau user cuma ngobrol biasa atau menanyakan sesuatu
            yang tidak butuh aksi apa pun, balas dengan teks biasa saja.

            Konteks {$memoryLimit} aktivitas terakhir (dari tabel memories):
            {$memoryContext}
            PROMPT;
    }

    /**
     * Dispatcher function call. `name` di sini WAJIB cocok dengan `name` yang
     * didefinisikan di AiToolRegistry.
     */
    private function handleFunctionCall(array $function): void
    {
        $name = $function['name'];
        $args = is_array($function['arguments']) ? $function['arguments'] : [];

        try {
            $resultText = match ($name) {
                'create_task' => $this->execCreateTask($args),
                'log_habit' => $this->execLogHabit($args),
                'record_transaction' => $this->execRecordTransaction($args),
                default => "⚠️ AI mencoba memanggil aksi yang belum aku kenal: <code>{$name}</code>.",
            };
        } catch (Throwable $e) {
            $resultText = "⚠️ Gagal menjalankan aksi <code>{$name}</code>: {$e->getMessage()}";
        }

        $this->telegram->sendMessage($this->chatId, $resultText);
        $this->rememberConversation("[Aksi: {$name}] ".json_encode($args, JSON_UNESCAPED_UNICODE)." -> {$resultText}");
    }

    /**
     * Modul Tasks — dipanggil lewat TaskController::store() langsung (bukan
     * ditulis ulang di sini) supaya efek sampingnya (ActivityLog, dst) tetap
     * konsisten dengan task yang dibuat lewat aplikasi/API biasa.
     */
    private function execCreateTask(array $args): string
    {
        $request = Request::create('/api/tasks', 'POST', [
            'title' => $args['title'] ?? 'Tanpa judul',
            'board_id' => $args['board_id'] ?? 1,
            'priority' => $args['priority'] ?? 'med',
            'due_at' => $args['due_at'] ?? null,
        ]);

        $response = (new TaskController())->store($request);
        $data = json_decode($response->getContent(), true);
        $title = $data['task']['title'] ?? ($args['title'] ?? 'tugas baru');

        return "✅ Sip, tugas <b>{$title}</b> sudah aku tambahkan ke board.";
    }

    /**
     * Modul Habits — SENGAJA tidak lewat HabitController::toggle(), karena
     * toggle() itu benar-benar toggle (dipanggil 2x saat habit sudah selesai
     * hari ini justru akan MEMBATALKANNYA). Di sini pakai firstOrCreate yang
     * idempotent: aman dipanggil berkali-kali dalam satu hari yang sama.
     */
    private function execLogHabit(array $args): string
    {
        $needle = trim((string) ($args['habit_title'] ?? ''));

        if ($needle === '') {
            return '⚠️ Nama habit-nya tidak jelas, sebutkan lagi ya nama habit yang mau ditandai selesai.';
        }

        // NOTE: kolom `archived` di sebagian besar baris ternyata NULL, bukan `false`
        // -- `where('archived', false)` polos akan diam-diam mengecualikan baris NULL
        // (semantik SQL: NULL = 0 bukan TRUE). Jadi anggap NULL sama dengan belum diarsip.
        $habit = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))
            ->where('title', 'like', '%'.$needle.'%')
            ->first();

        if (! $habit) {
            return "⚠️ Aku tidak menemukan habit dengan nama mirip \"{$needle}\". Coba sebut nama habit-nya sesuai daftar habit kamu ya.";
        }

        HabitLog::firstOrCreate(
            ['habit_id' => $habit->id, 'date' => now()->toDateString()],
            ['completed' => true, 'completed_at' => now(), 'notes' => $args['notes'] ?? null]
        );

        return "🔥 Mantap, habit <b>{$habit->title}</b> sudah aku tandai selesai hari ini!";
    }

    /**
     * Modul Finance — dipanggil lewat TransactionController::store() langsung
     * supaya saldo Account ikut ter-update lewat logika applyBalance() yang
     * sama persis dipakai jalur REST API biasa, bukan implementasi tandingan.
     * Akun tujuan sengaja default ke akun pertama (aplikasi ini masih 1 akun
     * per user) -- kalau nanti multi-akun, tools/prompt perlu ditambah
     * parameter account_name dulu.
     */
    private function execRecordTransaction(array $args): string
    {
        $account = Account::orderBy('created_at')->first();

        if (! $account) {
            return '⚠️ Belum ada akun finance yang terdaftar, bikin dulu akunnya di aplikasi ya.';
        }

        $type = $args['type'] ?? 'expense';

        $request = Request::create('/api/finance/transactions', 'POST', [
            'accountId' => $account->id,
            'type' => $type,
            'category' => $args['category'] ?? 'Other',
            'amount' => (float) ($args['amount'] ?? 0),
            'description' => $args['description'] ?? null,
            'transactionDate' => now()->toDateString(),
        ]);

        $response = (new TransactionController())->store($request);
        $data = json_decode($response->getContent(), true);

        $label = $type === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $amount = number_format((float) ($data['amount'] ?? $args['amount'] ?? 0), 0, ',', '.');
        $category = $data['category'] ?? ($args['category'] ?? 'Other');

        return "💸 {$label} Rp{$amount} ({$category}) sudah aku catat di akun {$account->name}.";
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
