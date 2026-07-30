<?php

namespace App\Telegram\Handlers;

use App\Http\Controllers\Api\Finance\TransactionController;
use App\Http\Controllers\Api\TaskController;
use App\Models\Account;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Memory;
use App\Models\Task;
use App\Models\Transaction;
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
 * Alurnya: ambil konteks dari tabel memories -> serahkan seluruhnya ke
 * AiService::chat() lengkap dengan daftar tools (AiToolRegistry) dan sebuah
 * dispatcher (executeTool()) yang tahu cara menjalankan tiap tool. AiService
 * yang mengurus loop rekursifnya sendiri (panggil tool -> sisipkan hasil ->
 * panggil AI lagi -- diulang sampai AI membalas teks biasa); AiHandler hanya
 * perlu tahu CARA mengeksekusi satu tool dan mengembalikan DATA MENTAHNYA.
 */
class AiHandler
{
    private const MEMORY_CONTEXT_LIMIT = 5;

    private const MAX_TOOL_ITERATIONS = 5;

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
        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($this->buildMemoryContext())],
            ['role' => 'user', 'content' => $this->text],
        ];

        try {
            $result = $this->ai->chat(
                $messages,
                AiToolRegistry::all(),
                fn (string $name, array $args) => $this->executeTool($name, $args),
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

            Konteks {$memoryLimit} aktivitas terakhir (dari tabel memories):
            {$memoryContext}
            PROMPT;
    }

    /**
     * Dispatcher tunggal untuk semua tool. Dipanggil oleh AiService::chat()
     * setiap kali AI minta eksekusi function.
     *
     * PENTING: method (dan semua tool*() di bawah) WAJIB me-return array DATA
     * MENTAH -- BUKAN string siap-kirim-Telegram -- karena hasilnya akan
     * di-encode jadi JSON dan disisipkan balik ke riwayat percakapan AI
     * (role "tool"), supaya AI yang merangkumnya jadi kalimat natural untuk
     * user di iterasi berikutnya. `name` di sini WAJIB cocok dengan `name`
     * yang didefinisikan di AiToolRegistry.
     */
    private function executeTool(string $name, array $args): array
    {
        return match ($name) {
            'get_tasks' => $this->toolGetTasks($args),
            'create_task' => $this->toolCreateTask($args),
            'update_task' => $this->toolUpdateTask($args),
            'delete_task' => $this->toolDeleteTask($args),
            'log_habit' => $this->toolLogHabit($args),
            'get_balances' => $this->toolGetBalances($args),
            'get_transactions' => $this->toolGetTransactions($args),
            'record_transaction' => $this->toolRecordTransaction($args),
            default => ['error' => "Tool tidak dikenal: {$name}"],
        };
    }

    /**
     * Modul Tasks -- READ.
     */
    private function toolGetTasks(array $args): array
    {
        $query = Task::query();

        if (! empty($args['board_id'])) {
            $query->where('board_id', $args['board_id']);
        }

        if (! empty($args['status'])) {
            $query->where('column_key', $args['status']);
        }

        if (! empty($args['search'])) {
            $query->where('title', 'like', '%'.$args['search'].'%');
        }

        if ($args['only_incomplete'] ?? true) {
            $query->whereNull('completed_at');
        }

        $tasks = $query->orderByDesc('created_at')->limit(20)->get();

        return [
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'board_id' => $t->board_id,
                'column' => $t->column_key,
                'priority' => $t->priority,
                'due_at' => $t->due_at?->toDateTimeString(),
                'completed' => $t->completed_at !== null,
            ])->values()->all(),
        ];
    }

    /**
     * Modul Tasks -- CREATE. Dipanggil lewat TaskController::store() langsung
     * (bukan ditulis ulang di sini) supaya efek sampingnya (ActivityLog, dst)
     * tetap konsisten dengan task yang dibuat lewat aplikasi/API biasa.
     */
    private function toolCreateTask(array $args): array
    {
        $request = Request::create('/api/tasks', 'POST', [
            'title' => $args['title'] ?? 'Tanpa judul',
            'board_id' => $args['board_id'] ?? 1,
            'priority' => $args['priority'] ?? 'med',
            'due_at' => $args['due_at'] ?? null,
        ]);

        $response = (new TaskController())->store($request);
        $data = json_decode($response->getContent(), true);

        return ['success' => true, 'task' => $data['task'] ?? $data];
    }

    /**
     * Modul Tasks -- UPDATE. Dipanggil lewat TaskController::update() supaya
     * efek samping (ActivityLog "Task updated"/"Moved to ..."/"Priority
     * changed to ...") tetap konsisten dengan update lewat aplikasi biasa.
     */
    private function toolUpdateTask(array $args): array
    {
        $taskId = $args['task_id'] ?? null;
        $task = $taskId ? Task::find($taskId) : null;

        if (! $task) {
            return ['error' => "Task dengan id {$taskId} tidak ditemukan. Panggil get_tasks dulu untuk cek ID yang benar."];
        }

        $fields = array_filter([
            'title' => $args['title'] ?? null,
            'priority' => $args['priority'] ?? null,
            'due_at' => $args['due_at'] ?? null,
            'column_key' => $args['column_key'] ?? null,
        ], fn ($v) => $v !== null);

        $request = Request::create("/api/tasks/{$task->id}", 'PATCH', $fields);
        $response = (new TaskController())->update($request, $task->id);
        $data = json_decode($response->getContent(), true);

        return ['success' => true, 'task' => $data['task'] ?? $data];
    }

    /**
     * Modul Tasks -- DELETE.
     */
    private function toolDeleteTask(array $args): array
    {
        $taskId = $args['task_id'] ?? null;
        $task = $taskId ? Task::find($taskId) : null;

        if (! $task) {
            return ['error' => "Task dengan id {$taskId} tidak ditemukan. Panggil get_tasks dulu untuk cek ID yang benar."];
        }

        $title = $task->title;
        (new TaskController())->destroy($task->id);

        return ['success' => true, 'deleted_task_id' => $taskId, 'deleted_title' => $title];
    }

    /**
     * Modul Habits -- CREATE (tandai selesai hari ini). SENGAJA tidak lewat
     * HabitController::toggle(), karena toggle() itu benar-benar toggle
     * (dipanggil 2x saat habit sudah selesai hari ini justru akan
     * MEMBATALKANNYA). Di sini pakai firstOrCreate yang idempotent: aman
     * dipanggil berkali-kali dalam satu hari yang sama.
     */
    private function toolLogHabit(array $args): array
    {
        $needle = trim((string) ($args['habit_title'] ?? ''));

        if ($needle === '') {
            return ['error' => 'Nama habit-nya tidak jelas, minta user menyebutkan lagi nama habit yang mau ditandai selesai.'];
        }

        // NOTE: kolom `archived` di sebagian besar baris ternyata NULL, bukan `false`
        // -- `where('archived', false)` polos akan diam-diam mengecualikan baris NULL
        // (semantik SQL: NULL = 0 bukan TRUE). Jadi anggap NULL sama dengan belum diarsip.
        $habit = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))
            ->where('title', 'like', '%'.$needle.'%')
            ->first();

        if (! $habit) {
            return ['error' => "Tidak ditemukan habit dengan nama mirip \"{$needle}\"."];
        }

        HabitLog::firstOrCreate(
            ['habit_id' => $habit->id, 'date' => now()->toDateString()],
            ['completed' => true, 'completed_at' => now(), 'notes' => $args['notes'] ?? null]
        );

        return ['success' => true, 'habit_title' => $habit->title];
    }

    /**
     * Modul Finance -- READ saldo.
     */
    private function toolGetBalances(array $args): array
    {
        $accounts = Account::all();

        return [
            'accounts' => $accounts->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'balance' => (float) $a->balance,
            ])->values()->all(),
            'total_balance' => (float) $accounts->sum('balance'),
        ];
    }

    /**
     * Modul Finance -- READ riwayat transaksi.
     */
    private function toolGetTransactions(array $args): array
    {
        $query = Transaction::query()->where(function ($q) {
            $q->whereNull('transfer_group_id')->orWhere('type', 'transfer');
        });

        if (! empty($args['type'])) {
            $query->where('type', $args['type']);
        }

        if (! empty($args['category'])) {
            $query->where('category', $args['category']);
        }

        if (! empty($args['from_date'])) {
            $query->whereDate('transaction_date', '>=', $args['from_date']);
        }

        if (! empty($args['to_date'])) {
            $query->whereDate('transaction_date', '<=', $args['to_date']);
        }

        $limit = min((int) ($args['limit'] ?? 20), 50);
        $transactions = $query->orderByDesc('transaction_date')->limit($limit)->get();

        return [
            'count' => $transactions->count(),
            'transactions' => $transactions->map(fn (Transaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'category' => $t->category,
                'amount' => (float) $t->amount,
                'description' => $t->description,
                'date' => $t->transaction_date?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Modul Finance -- CREATE transaksi. Dipanggil lewat
     * TransactionController::store() langsung supaya saldo Account ikut
     * ter-update lewat logika applyBalance() yang sama persis dipakai jalur
     * REST API biasa, bukan implementasi tandingan. Akun tujuan sengaja
     * default ke akun pertama (aplikasi ini masih 1 akun per user) -- kalau
     * nanti multi-akun, tools/prompt perlu ditambah parameter account_name dulu.
     */
    private function toolRecordTransaction(array $args): array
    {
        $account = Account::orderBy('created_at')->first();

        if (! $account) {
            return ['error' => 'Belum ada akun finance yang terdaftar, minta user membuat akunnya dulu di aplikasi.'];
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

        return ['success' => true, 'account_name' => $account->name, 'transaction' => $data];
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
