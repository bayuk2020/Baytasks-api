<?php

namespace App\Services\Ai;

use App\Http\Controllers\Api\Finance\AnalyticsController;
use App\Http\Controllers\Api\Finance\DebtController;
use App\Http\Controllers\Api\Finance\TransactionController;
use App\Http\Controllers\Api\PomodoroController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\TaskController;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\FinanceCategory;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Journal;
use App\Models\JournalTag;
use App\Models\Memory;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TelegramSetting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dispatcher & implementasi tool AI yang dipakai BERSAMA oleh semua surface
 * (Telegram AiHandler, Web AiWebController, dan surface lain di masa depan).
 * Diekstrak keluar dari AiHandler supaya benar-benar SATU implementasi --
 * bukan disalin ulang per-surface (persis pola yang sudah dipraktikkan di
 * Task/Transaction: tool di sini reuse Controller yang sudah ada, bukan
 * menulis ulang logikanya).
 *
 * PENTING: execute() (dan semua tool*() di bawah) WAJIB me-return array DATA
 * MENTAH -- BUKAN string siap-kirim-user -- karena hasilnya di-encode jadi
 * JSON dan disisipkan balik ke riwayat percakapan AI (role "tool") oleh
 * AiService::chat(), supaya AI yang merangkumnya jadi kalimat natural.
 */
class AiToolExecutor
{
    /**
     * Dispatcher tunggal untuk semua tool. `name` WAJIB cocok dengan `name`
     * yang didefinisikan di AiToolRegistry.
     */
    public function execute(string $name, array $args): array
    {
        return match ($name) {
            // --- Tasks ---
            'get_tasks' => $this->toolGetTasks($args),
            'create_task' => $this->toolCreateTask($args),
            'update_task' => $this->toolUpdateTask($args),
            'delete_task' => $this->toolDeleteTask($args),
            'add_subtasks' => $this->toolAddSubtasks($args),
            'complete_subtask' => $this->toolCompleteSubtask($args),
            'delete_subtask' => $this->toolDeleteSubtask($args),

            // --- Habits ---
            'get_habits' => $this->toolGetHabits($args),
            'create_habit' => $this->toolCreateHabit($args),
            'log_habit' => $this->toolLogHabit($args),

            // --- Finance: akun & transaksi ---
            'get_finance_categories' => $this->toolGetFinanceCategories($args),
            'get_balances' => $this->toolGetBalances($args),
            'create_account' => $this->toolCreateAccount($args),
            'get_transactions' => $this->toolGetTransactions($args),
            'record_transaction' => $this->toolRecordTransaction($args),

            // --- Finance: kontak, budget, utang, analitik ---
            'get_contacts' => $this->toolGetContacts($args),
            'create_contact' => $this->toolCreateContact($args),
            'get_budgets' => $this->toolGetBudgets($args),
            'create_budget' => $this->toolCreateBudget($args),
            'get_debts' => $this->toolGetDebts($args),
            'create_debt' => $this->toolCreateDebt($args),
            'record_debt_payment' => $this->toolRecordDebtPayment($args),
            'get_analytics' => $this->toolGetAnalytics($args),

            // --- Pomodoro / Focus ---
            'start_focus_session' => $this->toolStartFocusSession($args),
            'stop_focus_session' => $this->toolStopFocusSession($args),
            'log_focus_session' => $this->toolLogFocusSession($args),
            'get_focus_stats' => $this->toolGetFocusStats($args),

            // --- Journal & Story ---
            'get_journals' => $this->toolGetJournals($args),
            'create_journal' => $this->toolCreateJournal($args),
            'create_story' => $this->toolCreateStory($args),

            // --- Pengaturan ---
            'toggle_sleep_mode' => $this->toolToggleSleepMode($args),

            default => ['error' => "Tool tidak dikenal: {$name}"],
        };
    }

    /**
     * Konteks {$limit} aktivitas terakhir dari tabel memories, dipakai semua
     * surface untuk system prompt supaya AI "ingat" aktivitas terbaru.
     */
    public static function memoryContext(int $limit = 5): string
    {
        $memories = Memory::orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        if ($memories->isEmpty()) {
            return '(Belum ada riwayat aktivitas sebelumnya.)';
        }

        return $memories
            ->reverse()
            ->map(fn (Memory $m) => "- [{$m->occurred_at}] {$m->title}: {$m->content}")
            ->implode("\n");
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
            $needle = '%'.$args['search'].'%';
            $query->where(function ($q) use ($needle) {
                $q->where('title', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        if ($args['only_incomplete'] ?? true) {
            $query->whereNull('completed_at');
        }

        $tasks = $query->with('subtasks')->orderByDesc('created_at')->limit(20)->get();

        return [
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'board_id' => $t->board_id,
                'column' => $t->column_key,
                'priority' => $t->priority,
                'due_at' => $t->due_at?->toDateTimeString(),
                'completed' => $t->completed_at !== null,
                // Subtask ikut dikirim supaya AI tahu checklist yang SUDAH ada
                // (tidak menambah yang dobel) dan tahu subtask_id untuk
                // complete_subtask/delete_subtask.
                'subtasks' => $t->subtasks->map(fn (Subtask $s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'done' => (bool) $s->done,
                ])->values()->all(),
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
            // BUG LAMA: description tidak pernah diteruskan (bahkan tidak ada
            // di skema tool), jadi AI mustahil mengisinya walau user sudah
            // menceritakan detailnya panjang lebar -- deskripsi selalu kosong.
            'description' => $args['description'] ?? null,
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
            'description' => $args['description'] ?? null,
            'priority' => $args['priority'] ?? null,
            'due_at' => $args['due_at'] ?? null,
            'column_key' => $args['column_key'] ?? null,
        ], fn ($v) => $v !== null);

        // CATATAN: tidak perlu mengurus `completed_at` di sini.
        // TaskController::update() sekarang menentukannya sendiri dari
        // `column_key` (masuk "done" = selesai, keluar = batal selesai),
        // jadi semua jalur -- Kanban, TaskModal, AI, Telegram -- konsisten
        // tanpa logika kembar yang bisa berbeda satu sama lain.
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
     * Modul Tasks -- tambah SUBTASK (checklist) ke task yang sudah ada.
     *
     * CATATAN: subtask itu baris sendiri di tabel `subtasks` (punya id, done,
     * position), BUKAN teks yang ditempel ke kolom description task. Kalau
     * ditulis ke description, checklist-nya tidak akan muncul & tidak bisa
     * dicentang di UI aplikasi.
     */
    private function toolAddSubtasks(array $args): array
    {
        $taskId = $args['task_id'] ?? null;
        $task = $taskId ? Task::find($taskId) : null;

        if (! $task) {
            return ['error' => "Task dengan id {$taskId} tidak ditemukan. Panggil get_tasks dulu untuk cek ID yang benar."];
        }

        $titles = $args['titles'] ?? [];

        if (! is_array($titles)) {
            $titles = [$titles];
        }

        $titles = array_values(array_filter(
            array_map(fn ($t) => trim((string) $t), $titles),
            fn ($t) => $t !== ''
        ));

        if (empty($titles)) {
            return ['error' => 'Parameter titles wajib berisi minimal satu judul subtask.'];
        }

        $existing = $task->subtasks()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
        $position = (int) $task->subtasks()->max('position');

        $created = [];
        $skipped = [];

        foreach ($titles as $title) {
            // Anti-dobel: kalau subtask dengan judul sama sudah ada, lewati.
            if (in_array(mb_strtolower($title), $existing, true)) {
                $skipped[] = $title;

                continue;
            }

            $position++;

            $request = Request::create('/api/subtasks', 'POST', [
                'task_id' => $task->id,
                'title' => $title,
                'position' => $position,
            ]);

            $response = (new SubtaskController())->store($request);
            $data = json_decode($response->getContent(), true);

            $created[] = [
                'id' => $data['subtask']['id'] ?? null,
                'title' => $title,
            ];
            $existing[] = mb_strtolower($title);
        }

        return [
            'success' => true,
            'task_id' => $task->id,
            'task_title' => $task->title,
            'created' => $created,
            'skipped_because_duplicate' => $skipped,
        ];
    }

    /**
     * Modul Tasks -- centang/batal-centang satu subtask.
     */
    private function toolCompleteSubtask(array $args): array
    {
        $subtaskId = $args['subtask_id'] ?? null;
        $subtask = $subtaskId ? Subtask::find($subtaskId) : null;

        if (! $subtask) {
            return ['error' => "Subtask dengan id {$subtaskId} tidak ditemukan. Panggil get_tasks dulu untuk lihat daftar subtask beserta id-nya."];
        }

        $done = array_key_exists('done', $args)
            ? (filter_var($args['done'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true)
            : true;

        $request = Request::create("/api/subtasks/{$subtask->id}", 'PATCH', ['done' => $done]);
        (new SubtaskController())->update($request, $subtask->id);

        return [
            'success' => true,
            'subtask_id' => $subtask->id,
            'title' => $subtask->title,
            'done' => $done,
        ];
    }

    /**
     * Modul Tasks -- hapus satu subtask.
     */
    private function toolDeleteSubtask(array $args): array
    {
        $subtaskId = $args['subtask_id'] ?? null;
        $subtask = $subtaskId ? Subtask::find($subtaskId) : null;

        if (! $subtask) {
            return ['error' => "Subtask dengan id {$subtaskId} tidak ditemukan. Panggil get_tasks dulu untuk lihat daftar subtask beserta id-nya."];
        }

        $title = $subtask->title;
        $subtask->delete();

        return ['success' => true, 'deleted_subtask_id' => $subtaskId, 'deleted_title' => $title];
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
        $account = $this->resolveAccount($args['account_name'] ?? null);

        if (! $account) {
            return ['error' => 'Belum ada akun finance yang terdaftar. Panggil get_balances untuk cek, atau create_account untuk membuatnya dulu.'];
        }

        $type = $args['type'] ?? 'expense';
        $description = trim((string) ($args['description'] ?? ''));

        if ($description === '') {
            return ['error' => 'Parameter description WAJIB diisi -- isi dengan barang/jasa yang dibeli atau sumber uangnya, persis seperti yang disebut user (contoh: "Beli rokok"). Jangan dikosongkan.'];
        }

        // Kategori WAJIB salah satu yang benar-benar ada di finance_categories.
        // BUG LAMA: AI mengarang kategori dari kata benda yang disebut user
        // (mis. user bilang "beli rokok" -> category="rokok", description kosong),
        // padahal seharusnya description="Beli rokok" + category dipilih dari
        // daftar resmi (mis. "Consumption"). Di sini divalidasi ulang di server
        // supaya tidak bergantung pada kepatuhan AI saja.
        $category = trim((string) ($args['category'] ?? ''));
        $validCategories = FinanceCategory::where('type', $type === 'income' ? 'income' : 'expense')
            ->orderBy('sort_order')
            ->pluck('name');

        if ($validCategories->isNotEmpty()) {
            $matched = $validCategories->first(
                fn (string $c) => mb_strtolower($c) === mb_strtolower($category)
            );

            if (! $matched) {
                return [
                    'error' => "Kategori \"{$category}\" tidak ada di daftar kategori resmi. "
                        .'Pilih SATU yang paling cocok dari daftar valid di bawah, lalu panggil ulang '
                        .'record_transaction. Detail barang/jasanya taruh di description, BUKAN di category.',
                    'valid_categories' => $validCategories->values()->all(),
                    'your_description' => $description,
                ];
            }

            $category = $matched;
        }

        $contactId = null;
        if (! empty($args['contact_name'])) {
            $contact = Contact::where('name', 'like', '%'.trim($args['contact_name']).'%')->first();
            $contactId = $contact?->id;
        }

        $request = Request::create('/api/finance/transactions', 'POST', [
            'accountId' => $account->id,
            'type' => $type,
            'category' => $category !== '' ? $category : 'Other',
            'amount' => (float) ($args['amount'] ?? 0),
            'description' => $description,
            'transactionDate' => $args['date'] ?? now()->toDateString(),
            'contactId' => $contactId,
        ]);

        $response = (new TransactionController())->store($request);
        $data = json_decode($response->getContent(), true);

        return [
            'success' => true,
            'account_name' => $account->name,
            'category_used' => $category,
            'description_used' => $description,
            'transaction' => $data,
        ];
    }

    /**
     * Cari akun berdasarkan nama (pencocokan sebagian, case-insensitive).
     * Kalau nama tidak disebut/tidak ketemu, fallback ke akun pertama --
     * perilaku lama sebelum multi-akun didukung.
     */
    private function resolveAccount(?string $name): ?Account
    {
        if ($name !== null && trim($name) !== '') {
            $found = Account::where('name', 'like', '%'.trim($name).'%')->first();

            if ($found) {
                return $found;
            }
        }

        return Account::orderBy('created_at')->first();
    }

    /**
     * Pengaturan Bot -- Sleep Mode. Aplikasi ini single-user, jadi cukup update
     * baris telegram_settings pertama (tidak ada konsep per-chat/per-session
     * lagi di level shared executor ini -- baik Telegram maupun Web memakai
     * satu baris pengaturan yang sama).
     */
    private function toolToggleSleepMode(array $args): array
    {
        if (! array_key_exists('status', $args)) {
            return ['error' => 'Parameter status (true/false) wajib diisi.'];
        }

        $status = filter_var($args['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        $setting = TelegramSetting::first();

        if (! $setting) {
            return ['error' => 'Belum ada baris telegram_settings yang terdaftar.'];
        }

        $setting->update(['is_sleeping' => $status]);

        return [
            'success' => true,
            'is_sleeping' => $status,
            'message' => $status
                ? 'Sleep Mode diaktifkan -- semua reminder proaktif (task/habit/morning brief/nightly summary) dibungkam sampai dimatikan lagi.'
                : 'Sleep Mode dimatikan -- reminder proaktif jalan normal lagi.',
        ];
    }

    // =====================================================================
    // Modul Habits -- READ & CREATE
    // =====================================================================

    private function toolGetHabits(array $args): array
    {
        // `archived` banyak yang NULL, bukan false -- NULL dianggap belum diarsip.
        $habits = Habit::where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))
            ->orderBy('title')
            ->get();

        $today = now()->toDateString();
        $doneToday = HabitLog::where('date', $today)
            ->where('completed', true)
            ->pluck('habit_id')
            ->all();

        return [
            'count' => $habits->count(),
            'habits' => $habits->map(fn (Habit $h) => [
                'id' => $h->id,
                'title' => $h->title,
                'description' => $h->description,
                'frequency' => $h->frequency,
                'reminder_time' => $h->reminder_time,
                'due_time' => $h->due_time,
                'done_today' => in_array($h->id, $doneToday, true),
            ])->values()->all(),
        ];
    }

    private function toolCreateHabit(array $args): array
    {
        $title = trim((string) ($args['title'] ?? ''));

        if ($title === '') {
            return ['error' => 'Parameter title wajib diisi.'];
        }

        $habit = Habit::create([
            'user_id' => 1,
            'title' => $title,
            'description' => $args['description'] ?? null,
            'emoji' => $args['emoji'] ?? '🔥',
            'color' => 'cyan',
            'frequency' => $args['frequency'] ?? 'daily',
            'target' => 1,
            'xp_per_completion' => 25,
            'reminder_time' => $args['reminder_time'] ?? null,
            'due_time' => $args['due_time'] ?? null,
        ]);

        return ['success' => true, 'habit' => ['id' => $habit->id, 'title' => $habit->title]];
    }

    // =====================================================================
    // Modul Finance -- kategori, akun, kontak, budget, utang, analitik
    // =====================================================================

    /**
     * Daftar kategori RESMI. Wajib dibaca AI sebelum record_transaction
     * supaya tidak mengarang kategori sendiri dari kata benda yang disebut user.
     */
    private function toolGetFinanceCategories(array $args): array
    {
        $categories = FinanceCategory::orderBy('type')->orderBy('sort_order')->get();

        return [
            'income' => $categories->where('type', 'income')->pluck('name')->values()->all(),
            'expense' => $categories->where('type', 'expense')->pluck('name')->values()->all(),
        ];
    }

    private function toolCreateAccount(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return ['error' => 'Parameter name (nama akun/bank) wajib diisi.'];
        }

        if (Account::where('name', $name)->exists()) {
            return ['error' => "Akun dengan nama \"{$name}\" sudah ada. Jangan buat duplikatnya."];
        }

        $balance = (float) ($args['balance'] ?? 0);
        $type = $args['type'] ?? 'bank';

        // Kolom `color` di finance_accounts NOT NULL, jadi wajib diisi.
        // Warnanya disamakan dengan ACCOUNT_TYPE_META di frontend
        // (src/lib/finance/store.ts) supaya akun buatan AI tampil senada
        // dengan yang dibuat lewat UI.
        $colorByType = [
            'bank' => 'oklch(0.72 0.16 230)',
            'ewallet' => 'oklch(0.75 0.18 160)',
            'cash' => 'oklch(0.80 0.14 80)',
            'trading' => 'oklch(0.72 0.22 300)',
        ];

        $account = Account::create([
            'id' => (string) Str::uuid(),
            'user_id' => 1,
            'name' => $name,
            'type' => $type,
            'balance' => $balance,
            'opening_balance' => $balance,
            'icon' => 'wallet',
            'color' => $colorByType[$type] ?? $colorByType['bank'],
            'notes' => $args['notes'] ?? null,
            'is_active' => true,
        ]);

        return ['success' => true, 'account' => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'balance' => (float) $account->balance,
        ]];
    }

    private function toolGetContacts(array $args): array
    {
        $query = Contact::query();

        if (! empty($args['search'])) {
            $needle = '%'.trim($args['search']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('phone', 'like', $needle));
        }

        $contacts = $query->orderBy('name')->limit(50)->get();

        return [
            'count' => $contacts->count(),
            'contacts' => $contacts->map(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'phone' => $c->phone,
                'notes' => $c->notes,
            ])->values()->all(),
        ];
    }

    private function toolCreateContact(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return ['error' => 'Parameter name wajib diisi.'];
        }

        if (Contact::where('name', $name)->exists()) {
            return ['error' => "Kontak \"{$name}\" sudah ada. Jangan buat duplikatnya."];
        }

        $type = $args['type'] ?? 'person';
        $allowed = ['person', 'family', 'employee', 'vendor', 'customer', 'other'];

        if (! in_array($type, $allowed, true)) {
            return ['error' => 'Parameter type harus salah satu dari: '.implode(', ', $allowed)];
        }

        $contact = Contact::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'type' => $type,
            'phone' => $args['phone'] ?? null,
            'notes' => $args['notes'] ?? null,
        ]);

        return ['success' => true, 'contact' => [
            'id' => $contact->id,
            'name' => $contact->name,
            'type' => $contact->type,
        ]];
    }

    private function toolGetBudgets(array $args): array
    {
        $budgets = Budget::orderBy('category')->get();

        // Realisasi pengeluaran bulan berjalan per kategori, supaya AI bisa
        // langsung menjawab "budget makan sisa berapa" tanpa tool tambahan.
        $spentByCategory = Transaction::where('type', 'expense')
            ->whereNull('transfer_group_id')
            ->whereYear('transaction_date', now()->year)
            ->whereMonth('transaction_date', now()->month)
            ->get()
            ->groupBy('category')
            ->map(fn ($rows) => (float) $rows->sum('amount'));

        return [
            'month' => now()->format('Y-m'),
            'budgets' => $budgets->map(function (Budget $b) use ($spentByCategory) {
                $limit = (float) $b->monthly_limit;
                $spent = (float) ($spentByCategory[$b->category] ?? 0);

                return [
                    'id' => $b->id,
                    'category' => $b->category,
                    'monthly_limit' => $limit,
                    'spent_this_month' => $spent,
                    'remaining' => $limit - $spent,
                ];
            })->values()->all(),
        ];
    }

    private function toolCreateBudget(array $args): array
    {
        $category = trim((string) ($args['category'] ?? ''));
        $limit = (float) ($args['monthly_limit'] ?? 0);

        if ($category === '' || $limit <= 0) {
            return ['error' => 'Parameter category dan monthly_limit (> 0) wajib diisi.'];
        }

        if (Budget::where('category', $category)->exists()) {
            return ['error' => "Budget untuk kategori \"{$category}\" sudah ada. Jangan buat duplikatnya."];
        }

        $budget = Budget::create([
            'id' => (string) Str::uuid(),
            'user_id' => 1,
            'category' => $category,
            'monthly_limit' => $limit,
            'notes' => $args['notes'] ?? null,
            'is_active' => true,
        ]);

        return ['success' => true, 'budget' => [
            'id' => $budget->id,
            'category' => $budget->category,
            'monthly_limit' => (float) $budget->monthly_limit,
        ]];
    }

    private function toolGetDebts(array $args): array
    {
        $debts = Debt::orderByDesc('created_at')->get();

        return [
            'count' => $debts->count(),
            'total_remaining' => (float) $debts->sum('remaining_debt'),
            'debts' => $debts->map(fn (Debt $d) => [
                'id' => $d->id,
                'creditor' => $d->creditor,
                'total_debt' => (float) $d->total_debt,
                'remaining_debt' => (float) $d->remaining_debt,
                'monthly_payment' => (float) $d->monthly_payment,
                'due_date' => $d->due_date,
                'status' => $d->status,
            ])->values()->all(),
        ];
    }

    private function toolCreateDebt(array $args): array
    {
        $creditor = trim((string) ($args['creditor'] ?? ''));
        $total = (float) ($args['total_debt'] ?? 0);

        if ($creditor === '' || $total <= 0) {
            return ['error' => 'Parameter creditor dan total_debt (> 0) wajib diisi.'];
        }

        $debt = Debt::create([
            'id' => (string) Str::uuid(),
            'user_id' => 1,
            'creditor' => $creditor,
            'total_debt' => $total,
            'remaining_debt' => (float) ($args['remaining_debt'] ?? $total),
            'monthly_payment' => (float) ($args['monthly_payment'] ?? 0),
            'due_date' => $args['due_date'] ?? null,
            'notes' => $args['notes'] ?? null,
            'status' => 'active',
        ]);

        return ['success' => true, 'debt' => [
            'id' => $debt->id,
            'creditor' => $debt->creditor,
            'remaining_debt' => (float) $debt->remaining_debt,
        ]];
    }

    /**
     * Bayar cicilan utang. Lewat DebtController::payment() supaya efek
     * sampingnya konsisten: saldo akun berkurang + otomatis tercatat sebagai
     * Transaction kategori "Debt Payment".
     */
    private function toolRecordDebtPayment(array $args): array
    {
        $creditor = trim((string) ($args['creditor'] ?? ''));
        $amount = (float) ($args['amount'] ?? 0);

        if ($creditor === '' || $amount <= 0) {
            return ['error' => 'Parameter creditor dan amount (> 0) wajib diisi.'];
        }

        $debt = Debt::where('creditor', 'like', '%'.$creditor.'%')->first();

        if (! $debt) {
            return ['error' => "Tidak ada utang dengan kreditur mirip \"{$creditor}\". Panggil get_debts dulu untuk lihat daftarnya. JANGAN buat utang baru."];
        }

        $account = $this->resolveAccount($args['account_name'] ?? null);

        if (! $account) {
            return ['error' => 'Belum ada akun finance untuk sumber pembayaran.'];
        }

        $request = Request::create("/api/finance/debts/{$debt->id}/payment", 'POST', [
            'account_id' => $account->id,
            'amount' => $amount,
            'paid_at' => $args['paid_at'] ?? now()->toDateString(),
            'notes' => $args['notes'] ?? "Pembayaran utang {$debt->creditor}",
        ]);

        $response = (new DebtController())->payment($request, $debt);
        $data = json_decode($response->getContent(), true);

        return ['success' => true, 'account_name' => $account->name, 'result' => $data];
    }

    private function toolGetAnalytics(array $args): array
    {
        $params = array_filter([
            'tahun' => $args['year'] ?? null,
            'bulan' => $args['month'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $request = Request::create('/api/finance/analytics', 'GET', $params);
        $response = (new AnalyticsController())->__invoke($request);

        return json_decode($response->getContent(), true) ?? [];
    }

    // =====================================================================
    // Modul Pomodoro / Focus
    // =====================================================================

    /**
     * Mulai sesi fokus. Sesi disimpan sebagai baris TERBUKA (ended_at NULL)
     * lewat PomodoroController::start(), jadi timer yang dinyalakan dari sini
     * juga terlihat di widget header web -- bukan timer terpisah.
     */
    private function toolStartFocusSession(array $args): array
    {
        $mode = $args['mode'] ?? 'focus';

        $request = Request::create('/api/pomodoro/start', 'POST', ['mode' => $mode]);
        $response = (new PomodoroController())->start($request);
        $data = json_decode($response->getContent(), true);

        return [
            'success' => true,
            'mode' => $mode,
            'started_at' => $data['session']['startedAt'] ?? null,
            'closed_previous' => $data['closedPrevious'] ?? null,
            'note' => 'Timer sudah berjalan. Panggil stop_focus_session kalau user bilang sudah selesai.',
        ];
    }

    /**
     * Hentikan sesi yang sedang berjalan & catat durasinya.
     */
    private function toolStopFocusSession(array $args): array
    {
        $response = (new PomodoroController())->stop(Request::create('/api/pomodoro/stop', 'POST'));
        $data = json_decode($response->getContent(), true);

        if (! ($data['success'] ?? false)) {
            return ['error' => 'Tidak ada sesi fokus yang sedang berjalan. Mulai dulu dengan start_focus_session.'];
        }

        $seconds = (int) ($data['session']['durationSeconds'] ?? 0);

        return [
            'success' => true,
            'mode' => $data['session']['mode'] ?? null,
            'duration_seconds' => $seconds,
            'duration_human' => $this->humanDuration($seconds),
        ];
    }

    /**
     * Catat sesi fokus yang SUDAH LEWAT dari rentang jam yang disebut user
     * (mis. "tadi jam 09.00-11.20 aku pasang jaringan"). Tanpa ini, kerja
     * yang tidak dipandu timer tidak akan pernah masuk hitungan fokus.
     */
    private function toolLogFocusSession(array $args): array
    {
        $start = $args['start_time'] ?? null;
        $end = $args['end_time'] ?? null;

        if (! $start || ! $end) {
            return ['error' => 'Parameter start_time dan end_time wajib diisi, format "YYYY-MM-DD HH:MM".'];
        }

        try {
            $startedAt = Carbon::parse($start);
            $endedAt = Carbon::parse($end);
        } catch (\Throwable $e) {
            return ['error' => 'Format waktu tidak dikenali. Pakai "YYYY-MM-DD HH:MM", mis. "2026-08-05 09:00".'];
        }

        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            return ['error' => 'end_time harus setelah start_time.'];
        }

        $request = Request::create('/api/pomodoro/sessions', 'POST', [
            'mode' => $args['mode'] ?? 'focus',
            'startedAt' => $startedAt->toIso8601String(),
            'endedAt' => $endedAt->toIso8601String(),
            'completed' => true,
        ]);

        $response = (new PomodoroController())->store($request);
        $data = json_decode($response->getContent(), true);
        $seconds = (int) ($data['session']['durationSeconds'] ?? 0);

        return [
            'success' => true,
            'mode' => $data['session']['mode'] ?? 'focus',
            'duration_seconds' => $seconds,
            'duration_human' => $this->humanDuration($seconds),
        ];
    }

    /**
     * Ringkasan fokus: total hari ini + Score, plus beberapa hari terakhir.
     */
    private function toolGetFocusStats(array $args): array
    {
        $controller = new PomodoroController();

        $today = json_decode(
            $controller->today(Request::create('/api/pomodoro/today', 'GET'))->getContent(),
            true
        );

        $days = min(max((int) ($args['days'] ?? 7), 1), 30);
        $stats = json_decode(
            $controller->stats(Request::create('/api/pomodoro/stats', 'GET', ['days' => $days]))->getContent(),
            true
        );

        $rows = $stats['rows'] ?? [];
        $todayRow = collect($rows)->firstWhere('date', $today['date'] ?? null);

        $activeResponse = json_decode($controller->active()->getContent(), true);

        return [
            'today' => [
                'date' => $today['date'] ?? null,
                'focus_seconds' => $today['focusSeconds'] ?? 0,
                'focus_human' => $this->humanDuration((int) ($today['focusSeconds'] ?? 0)),
                'short_break_human' => $this->humanDuration((int) ($today['shortBreakSeconds'] ?? 0)),
                'long_break_human' => $this->humanDuration((int) ($today['longBreakSeconds'] ?? 0)),
                'session_count' => $today['sessionCount'] ?? 0,
                'score' => $todayRow['score'] ?? 0,
                'tasks_added' => $todayRow['tasksAdded'] ?? 0,
                'tasks_completed' => $todayRow['tasksCompleted'] ?? 0,
            ],
            'score_explanation' => 'Score 0-100 = 45% volume fokus (target 4 jam/hari) '
                .'+ 25% rasio fokus vs istirahat + 30% rasio task selesai.',
            'session_running_now' => $activeResponse['active'] ?? null,
            'recent_days' => array_map(fn ($r) => [
                'date' => $r['date'],
                'focus_human' => $this->humanDuration((int) $r['focusSeconds']),
                'score' => $r['score'],
                'tasks_completed' => $r['tasksCompleted'],
            ], $rows),
        ];
    }

    /** Detik -> "2 jam 20 menit" untuk dibacakan AI ke user. */
    private function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 menit';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $parts = [];
        if ($h > 0) {
            $parts[] = "{$h} jam";
        }
        if ($m > 0) {
            $parts[] = "{$m} menit";
        }
        if ($h === 0 && $s > 0) {
            $parts[] = "{$s} detik";
        }

        return implode(' ', $parts);
    }

    // =====================================================================
    // Modul Journal & Story
    // =====================================================================

    private function toolGetJournals(array $args): array
    {
        $query = Journal::with('tags');

        if (! empty($args['search'])) {
            $needle = '%'.trim($args['search']).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $needle)->orWhere('content', 'like', $needle));
        }

        $journals = $query->latest()->limit(15)->get();

        return [
            'count' => $journals->count(),
            'journals' => $journals->map(fn (Journal $j) => [
                'id' => $j->id,
                'title' => $j->title,
                // Konten journal itu HTML dari rich-text editor -- dibersihkan
                // & dipotong supaya tidak membanjiri context window AI.
                'excerpt' => Str::limit(strip_tags((string) $j->content), 300),
                'mood' => $j->mood,
                'created_at' => $j->created_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    private function toolCreateJournal(array $args): array
    {
        $title = trim((string) ($args['title'] ?? ''));
        $content = trim((string) ($args['content'] ?? ''));

        if ($title === '' || $content === '') {
            return ['error' => 'Parameter title dan content wajib diisi.'];
        }

        $journal = Journal::create([
            'title' => $title,
            // Journal disimpan sebagai HTML (editor-nya rich text) -- bungkus
            // tiap baris jadi <p> supaya tampil rapi, bukan satu blok mentah.
            'content' => collect(preg_split('/\r\n|\r|\n/', $content))
                ->filter(fn ($line) => trim($line) !== '')
                ->map(fn ($line) => '<p>'.e(trim($line)).'</p>')
                ->implode(''),
            'mood' => $args['mood'] ?? 'neutral',
        ]);

        foreach ($args['tags'] ?? [] as $tag) {
            JournalTag::create(['journal_id' => $journal->id, 'tag' => $tag]);
        }

        return ['success' => true, 'journal' => ['id' => $journal->id, 'title' => $journal->title]];
    }

    private function toolCreateStory(array $args): array
    {
        $caption = trim((string) ($args['caption'] ?? ''));

        if ($caption === '') {
            return ['error' => 'Parameter caption wajib diisi (Story tanpa gambar minimal harus punya teks).'];
        }

        $story = Story::create(['image_path' => null, 'caption' => $caption]);

        return ['success' => true, 'story' => ['id' => $story->id, 'caption' => $story->caption]];
    }
}
