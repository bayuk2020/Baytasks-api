<?php

namespace App\Telegram\Handlers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use App\Telegram\Core\TelegramSessionManager;
use App\Models\Task;
use App\Models\Subtask;
use App\Models\Memory;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TaskHandler
{
    protected Request $request;
    protected TelegramService $telegram;
    protected TelegramSessionManager $sessionManager;
    protected int $chatId;
    protected ?string $text;
    protected ?string $callbackData;
    protected ?int $messageId;
    protected \stdClass $session;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->telegram = new TelegramService();
        $this->sessionManager = new TelegramSessionManager();

        $this->chatId = $request->input('message.chat.id') ?? $request->input('callback_query.message.chat.id');
        $this->text = $request->input('message.text');
        $this->callbackData = $request->input('callback_query.data');
        $this->messageId = $request->input('callback_query.message.message_id');
        $this->session = $this->sessionManager->getSession($this->chatId);
    }

    public function handle()
    {
        // Jalur 1: Penanganan Callback dari tombol inline
        if ($this->callbackData) {
            $this->handleCallback();
        }
        // Jalur 2: Penanganan input teks manual dari user
        elseif ($this->text) {
            $this->handleTextInput();
        }
    }

    private function handleCallback()
    {
        if (in_array($this->callbackData, ['pulse_kerja', 'pulse_belajar', 'pulse_lainnya'])) {
            $this->handlePulseCheck();
        } elseif ($this->callbackData === 'intercept_target_tasks') {
            $this->startWizard();
        } elseif (str_starts_with($this->callbackData, 'wiz_')) {
            $this->processWizard($this->callbackData);
        } elseif (str_starts_with($this->callbackData, 'manual_board_')) {
            $this->showBoardTasks();
        } elseif (str_starts_with($this->callbackData, 'select_task_')) {
            $taskId = (int)str_replace('select_task_', '', $this->callbackData);
            $this->renderTaskSensation($taskId, false);
        } elseif (str_starts_with($this->callbackData, 'action_')) {
            $this->handleActionButtons();
        } elseif (str_starts_with($this->callbackData, 'set_prio_')) {
            $this->handlePriorityChange();
        } elseif (str_starts_with($this->callbackData, 'sub_already_a_')) {
            $this->handleSubtaskRevisit();
        } elseif (str_starts_with($this->callbackData, 'sub_already_b_')) {
            $this->handleSubtaskAddMore();
        } elseif ($this->callbackData === 'intercept_target_memories') {
            $this->promptForInterruptionTask();
        }
    }

    private function handleTextInput()
    {
        if ($this->session->step === 'task_wizard_running') {
            $this->processWizard($this->text);
        } elseif ($this->session->step === 'waiting_desc_update') {
            $this->updateTaskField('description', 'Deskripsi');
        } elseif ($this->session->step === 'waiting_notes_update') {
            $this->updateTaskField('notes', 'Notes');
        } elseif ($this->session->step === 'waiting_deadline_input') {
            $this->updateTaskDeadline();
        } elseif ($this->session->step === 'waiting_subtask') {
            $this->handleSubtaskInput();
        } elseif ($this->session->step === 'waiting_interruption_activity_legacy') {
            $this->createInterruptionTask();
        }
    }

    private function handlePulseCheck()
    {
        $activity = str_replace('pulse_', '', $this->callbackData);

        if ($activity === 'kerja' || $activity === 'belajar') {
            $targetBoard = ($activity === 'belajar') ? 4 : 2;
            $label = ($activity === 'belajar') ? 'belajar/kuliah' : 'kerjaan';
            $openTasks = Task::where('board_id', $targetBoard)->where('column_key', '!=', 'done')->get();

            if ($openTasks->isEmpty()) {
                $this->telegram->editMessageText($this->chatId, $this->messageId, "🎉 <b>All Clear, Bay!</b> Gak ada tugas nunggak di board {$label}mu.");
                return;
            }

            $buttons = [];
            foreach ($openTasks as $t) {
                $buttons[] = [['text' => "📌 " . mb_strimwidth($t->title, 0, 60, "..."), 'callback_data' => 'select_task_' . $t->id]];
            }
            $buttons[] = [['text' => '🌐 Aktivitas Lainnya', 'callback_data' => 'pulse_lainnya']];

            $this->sessionManager->updateSession($this->chatId, ['step' => 'task_selection', 'context_data' => json_encode(['activity_type' => $activity])]);
            $this->telegram->editMessageText($this->chatId, $this->messageId, "💻 <b>Lagi ngerjain apa sekarang, Bay?</b>\nPilih dari daftar tugas {$label}mu yang belum done di bawah ini:", ['reply_markup' => ['inline_keyboard' => $buttons]]);
        
        } elseif ($activity === 'lainnya') {
            $keyboard = ['inline_keyboard' => [[['text' => '🗂️ Masuk Memories', 'callback_data' => 'intercept_target_memories'], ['text' => '📋 Masuk Tasks', 'callback_data' => 'intercept_target_tasks']]]];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "🌐 <b>Silakan ketik aktivitas Anda saat ini secara manual:</b>\n\nSebelum lanjut, tentukan ke mana sistem harus mengarsipkan aktivitas baru ini, Bay?", ['reply_markup' => $keyboard]);
        }
    }

    private function promptForInterruptionTask()
    {
        $this->telegram->editMessageText($this->chatId, $this->messageId, "💥 <b>kok ga ngerjain yang di To Do List? lagi ngerjain apa?</b>\n\nKetik aktivitas daruratmu saat ini, sistem akan mencatatnya langsung ke memories kawan:");
        $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_interruption_activity_legacy']);
    }

    public function startWizard()
    {
        $this->telegram->editMessageText($this->chatId, $this->messageId, "⚙️ <b>Memulai Wizard Form Tugas Interaktif</b>\n\n<b>[Step 1 / 10]</b>\n✍️ Masukkan <b>Judul Tugas</b> yang wajib diisi:\n\n<i>(Ketik manual langsung lewat chat)</i>");
        $initialState = json_encode(['current_step' => 'wizard_title', 'data' => []]);
        $this->sessionManager->updateSession($this->chatId, ['step' => 'task_wizard_running', 'form_state' => $initialState]);
    }

    private function processWizard($input)
    {
        $state = json_decode($this->session->form_state, true) ?? ['current_step' => 'wizard_title', 'data' => []];
        $currentStep = $state['current_step'];

        if ($input === 'wiz_cancel') {
            $this->telegram->sendMessage($this->chatId, "❌ <b>Wizard Form dibatalkan.</b>");
            $this->sessionManager->clearSession($this->chatId);
            return;
        }

        switch ($currentStep) {
            case 'wizard_title':
                $state['data']['title'] = $input;
                $state['current_step'] = 'wizard_desc';
                $keyboard = ['inline_keyboard' => [[['text' => 'skip Deskripsi', 'callback_data' => 'wiz_skip_desc'], ['text' => '❌ Batal', 'callback_data' => 'wiz_cancel']]]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 2 / 10] Description?</b>\nKetik deskripsi tugas Anda:", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_desc':
                $state['data']['description'] = ($input === 'wiz_skip_desc') ? null : $input;
                $state['current_step'] = 'wizard_subtask';
                $state['data']['subtasks'] = [];
                $keyboard = ['inline_keyboard' => [[['text' => '+ Add Subtask', 'callback_data' => 'wiz_add_subtask'], ['text' => '⏭️ Next (Skip)', 'callback_data' => 'wiz_next_subtask']]]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 3 / 10] Subtask?</b>", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_subtask':
                if ($input === 'wiz_add_subtask' || $input === 'wiz_more_subtask') {
                    $state['current_step'] = 'wizard_subtask_collecting';
                    $this->telegram->sendMessage($this->chatId, "✍️ Silakan ketik judul subtask barumu:");
                } elseif ($input === 'wiz_next_subtask') {
                    $state['current_step'] = 'wizard_notes';
                    $keyboard = ['inline_keyboard' => [[['text' => 'skip Notes', 'callback_data' => 'wiz_skip_notes']]]];
                    $this->telegram->sendMessage($this->chatId, "<b>[Step 4 / 10] Notes?</b>", ['reply_markup' => $keyboard]);
                }
                break;

            case 'wizard_subtask_collecting':
                $state['data']['subtasks'][] = $input;
                $state['current_step'] = 'wizard_subtask';
                $count = count($state['data']['subtasks']);
                $keyboard = ['inline_keyboard' => [[['text' => '+ Tambah Lagi', 'callback_data' => 'wiz_more_subtask'], ['text' => '⏭️ Next (Selesai)', 'callback_data' => 'wiz_next_subtask']]]];
                $this->telegram->sendMessage($this->chatId, "✅ Sukses merekam {$count} subtask.", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_notes':
                $state['data']['notes'] = ($input === 'wiz_skip_notes') ? null : $input;
                $state['current_step'] = 'wizard_status';
                $keyboard = ['inline_keyboard' => [[['text' => 'Backlog', 'callback_data' => 'wiz_status_backlog'], ['text' => 'To Do', 'callback_data' => 'wiz_status_todo'], ['text' => 'In Progress', 'callback_data' => 'wiz_status_in_progress']],[['text' => 'Review', 'callback_data' => 'wiz_status_review'], ['text' => 'Done', 'callback_data' => 'wiz_status_done']]]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 5 / 10] Status?</b>", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_status':
                $statusMap = ['wiz_status_backlog' => 'backlog', 'wiz_status_todo' => 'todo', 'wiz_status_in_progress' => 'in_progress', 'wiz_status_review' => 'review', 'wiz_status_done' => 'done'];
                $state['data']['column_key'] = $statusMap[$input] ?? 'todo';
                $state['current_step'] = 'wizard_priority';
                $keyboard = ['inline_keyboard' => [[['text' => 'LOW', 'callback_data' => 'wiz_prio_low'], ['text' => 'MED', 'callback_data' => 'wiz_prio_med']],[['text' => 'HIGH', 'callback_data' => 'wiz_prio_high'], ['text' => 'URGENT', 'callback_data' => 'wiz_prio_urgent']]]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 6 / 10] Priority?</b>", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_priority':
                $prioMap = ['wiz_prio_low' => 'low', 'wiz_prio_med' => 'med', 'wiz_prio_high' => 'high', 'wiz_prio_urgent' => 'urgent'];
                $state['data']['priority'] = $prioMap[$input] ?? 'med';
                $state['current_step'] = 'wizard_duedate_check';
                $keyboard = ['inline_keyboard' => [[['text' => 'Ada', 'callback_data' => 'wiz_due_yes'], ['text' => '⏭️ Tidak, Next', 'callback_data' => 'wiz_due_no']]]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 7 / 10] Ada Due Date?</b>", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_duedate_check':
                if ($input === 'wiz_due_yes') {
                    $state['current_step'] = 'wizard_duedate_collecting';
                    $this->telegram->sendMessage($this->chatId, "✍️ <b>Kapan Deadline nya?</b>");
                } else {
                    $state['data']['due_at'] = null;
                    $state['current_step'] = 'wizard_reminder';
                    $keyboard = ['inline_keyboard' => [[['text' => 'None', 'callback_data' => 'wiz_rem_none'], ['text' => '10 Min', 'callback_data' => 'wiz_rem_10m']], [['text' => '1 Hour', 'callback_data' => 'wiz_rem_1h'], ['text' => '1 Day', 'callback_data' => 'wiz_rem_1d']]]];
                    $this->telegram->sendMessage($this->chatId, "<b>[Step 8 / 10] Next, kasih reminder ga?</b>", ['reply_markup' => $keyboard]);
                }
                break;

            case 'wizard_duedate_collecting':
                $parsedDate = $this->parseNaturalLanguageDate($input);
                $state['data']['due_at'] = $parsedDate->toDateTimeString();
                $state['current_step'] = 'wizard_reminder';
                $keyboard = ['inline_keyboard' => [[['text' => 'None', 'callback_data' => 'wiz_rem_none'], ['text' => '10 Min', 'callback_data' => 'wiz_rem_10m']], [['text' => '1 Hour', 'callback_data' => 'wiz_rem_1h'], ['text' => '1 Day', 'callback_data' => 'wiz_rem_1d']]]];
                $this->telegram->sendMessage($this->chatId, "🎯 Jadwal dideteksi: <b>" . $parsedDate->format('d M Y H:i') . " WIB</b>\n\n<b>[Step 8 / 10] Next, kasih reminder ga?</b>", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_reminder':
                $remMap = ['wiz_rem_none' => null, 'wiz_rem_10m' => '10m', 'wiz_rem_1h' => '1h', 'wiz_rem_1d' => '1d'];
                $state['data']['reminder'] = $remMap[$input] ?? null;
                $state['current_step'] = 'wizard_recurring';
                $keyboard = ['inline_keyboard' => [[['text' => 'None', 'callback_data' => 'wiz_rec_none'], ['text' => 'daily', 'callback_data' => 'wiz_rec_daily']], [['text' => 'weekly', 'callback_data' => 'wiz_rec_weekly'], ['text' => 'monthly', 'callback_data' => 'wiz_rec_monthly']]]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 9 / 10] Recurring?</b>", ['reply_markup' => $keyboard]);
                break;

            case 'wizard_recurring':
                $recMap = ['wiz_rec_none' => 'none', 'wiz_rec_daily' => 'daily', 'wiz_rec_weekly' => 'weekly', 'wiz_rec_monthly' => 'monthly'];
                $state['data']['recurring'] = $recMap[$input] ?? 'none';
                $state['current_step'] = 'wizard_tags';
                $this->telegram->sendMessage($this->chatId, "<b>[Step 10 / 10] kasih tags apa? (pisahkan dengan koma)</b>");
                break;

            case 'wizard_tags':
                $tagsArray = array_map('trim', explode(',', $input));
                $finalTask = Task::create([
                    'board_id' => 2, 'user_id' => 1, 'title' => $state['data']['title'],
                    'description' => $state['data']['description'] ?? null, 'notes' => $state['data']['notes'] ?? null,
                    'column_key' => $state['data']['column_key'] ?? 'todo', 'priority' => $state['data']['priority'] ?? 'med',
                    'tags' => $tagsArray, 'due_at' => $state['data']['due_at'] ?? null, 'reminder' => $state['data']['reminder'] ?? null,
                    'recurring' => $state['data']['recurring'] ?? 'none', 'position' => 0, 'reminded' => false
                ]);

                if (!empty($state['data']['subtasks'])) {
                    foreach ($state['data']['subtasks'] as $pos => $subTitle) {
                        Subtask::create(['task_id' => $finalTask->id, 'title' => $subTitle, 'done' => false, 'position' => $pos]);
                    }
                }

                $motivations = ["Semangat, lanjutin pengerjaannya kawan! 💪🔥", "Gas terus su, kerja keras takkan mengkhianati hasil! 💵🤑", "Fokus Bay, beresin itu kerjaan biar cepet santai lagi! 🚀"];
                $this->telegram->sendMessage($this->chatId, "💾 <b>Tugas Baru Berhasil Disimpan ke MySQL Database.</b>");
                $this->telegram->sendMessage($this->chatId, $motivations[array_rand($motivations)]);
                $this->sessionManager->clearSession($this->chatId);
                return;
        }

        $this->sessionManager->updateSession($this->chatId, ['form_state' => json_encode($state)]);
    }

    private function showBoardTasks()
    {
        $boardId = (int)str_replace('manual_board_', '', $this->callbackData);
        $openTasks = Task::where('board_id', $boardId)->where('column_key', '!=', 'done')->get();

        if ($openTasks->isEmpty()) {
            $this->telegram->editMessageText($this->chatId, $this->messageId, "🎉 Bersih, Bay! Gak ada tugas nunggak di board ini.");
            return;
        }

        $buttons = [];
        foreach ($openTasks as $t) {
            $buttons[] = [['text' => "📌 " . mb_strimwidth($t->title, 0, 60, "..."), 'callback_data' => 'select_task_' . $t->id]];
        }
        $this->telegram->editMessageText($this->chatId, $this->messageId, "📑 <b>Daftar Tugas Aktif:</b>\nSilakan pilih tugas untuk dikelola:", ['reply_markup' => ['inline_keyboard' => $buttons]]);
    }

    private function renderTaskSensation(int $taskId, bool $isNewMessage = false)
    {
        $task = Task::find($taskId);
        if (!$task) return;

        $subtasks = DB::table('subtasks')->where('task_id', $task->id)->orderBy('position', 'asc')->get();
        $subtext = "";
        if ($subtasks->isEmpty()) {
            $subtext = "<i>(Belum ada subtask)</i>\n";
        } else {
            foreach ($subtasks as $i => $sub) {
                $num = $i + 1;
                $subtext .= $sub->done
                    ? "✅ {$num}. ~{$sub->title}~\n"
                    : "❌ {$num}. {$sub->title}\n";
            }
        }

        $message = "🎯 <b>Sesi Fokus Tugas Atraktif</b>\n\n" .
                   "• <b>Task:</b> {$task->title}\n" .
                   "• <b>Description:</b> " . ($task->description ?? '-') . "\n" .
                   "• <b>Notes:</b> " . ($task->notes ?? '-') . "\n\n" .
                   "📝 <b>Daftar Subtasks:</b>\n{$subtext}\n" .
                   "💡 <i>Ketik <code>{nomor} done</code> untuk mencentang (Contoh: <code>1 done</code>).\n" .
                   "💡 Ketik <code>{nomor} undone</code> untuk mengaktifkan kembali (Contoh: <code>2 undone</code>).\n" .
                   "💡 Ketik angka urutan jika ingin memulai eksekusi subtask.\n" .
                   "💡 Ketik langsung jika ada subtask lain untuk menyisipkan subtask baru.</i>";

        $actionButtons = [
            'inline_keyboard' => [
                [['text' => '🛠️ Kerjakan Tugas', 'callback_data' => 'action_execute_' . $task->id], ['text' => '✅ Selesaikan (Done)', 'callback_data' => 'action_done_' . $task->id]],
                [['text' => '📅 Mundurkan Deadline', 'callback_data' => 'action_delay_' . $task->id], ['text' => '⚡ Ubah Prioritas', 'callback_data' => 'action_priority_' . $task->id]],
                [['text' => '📝 Ubah Deskripsi', 'callback_data' => 'action_updatedesc_' . $task->id], ['text' => '📌 Ubah Notes', 'callback_data' => 'action_updatenotes_' . $task->id]],
                [['text' => '🔙 Ganti Tugas Lain', 'callback_data' => 'action_back_' . $task->id]]
            ]
        ];

        $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_subtask', 'active_task_id' => $task->id]);

        if ($this->messageId && !$isNewMessage) {
            $this->telegram->editMessageText($this->chatId, $this->messageId, $message, ['reply_markup' => $actionButtons]);
        } else {
            $this->telegram->sendMessage($this->chatId, $message, ['reply_markup' => $actionButtons]);
        }
    }

    private function handleActionButtons()
    {
        $parts = explode('_', str_replace('action_', '', $this->callbackData));
        $action = $parts[0];
        $taskId = $parts[1] ?? null;

        if ($action === 'back') {
            $keyboard = ['inline_keyboard' => [[['text' => '💻 Kerjaan (Board 2)', 'callback_data' => 'manual_board_2'], ['text' => '🌱 Personal (Board 4)', 'callback_data' => 'manual_board_4']]]];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "📋 <b>Pilih Papan Kerja Kamu, Bay:</b>", ['reply_markup' => $keyboard]);
            return;
        }

        $task = Task::find($taskId);
        if (!$task) return;

        switch ($action) {
            case 'done':
                $task->update(['completed_at' => now(), 'column_key' => 'done']);
                $this->telegram->editMessageText($this->chatId, $this->messageId, "✅ <b>Mantap, lanjutkan tugas yang lain!</b>\nTugas \"{$task->title}\" berhasil ditutup.");
                $this->sessionManager->clearSession($this->chatId);
                break;
            case 'delay':
                $this->telegram->sendMessage($this->chatId, "📅 <b>Ketik jadwal deadline baru kawan:</b> (Contoh: besok jam 10, tanggal 25 14:30)");
                $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_deadline_input', 'active_task_id' => $task->id]);
                break;
            case 'updatedesc':
                $this->telegram->sendMessage($this->chatId, "✍️ <b>Ketik isi deskripsi baru, Bay:</b>");
                $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_desc_update', 'active_task_id' => $task->id]);
                break;
            case 'updatenotes':
                $this->telegram->sendMessage($this->chatId, "📌 <b>Ketik isi catatan (notes) baru kawan:</b>");
                $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_notes_update', 'active_task_id' => $task->id]);
                break;
            case 'priority':
                $keyboard = ['inline_keyboard' => [[['text' => 'Low', 'callback_data' => 'set_prio_low_' . $taskId], ['text' => 'Med', 'callback_data' => 'set_prio_med_' . $taskId]], [['text' => 'High', 'callback_data' => 'set_prio_high_' . $taskId], ['text' => 'Urgent', 'callback_data' => 'set_prio_urgent_' . $taskId]]]];
                $this->telegram->editMessageText($this->chatId, $this->messageId, "⚡ <b>Pilih tingkat prioritas baru kawan:</b>", ['reply_markup' => $keyboard]);
                break;
            case 'execute':
                DB::table('memories')->insert(['type' => 'task_log', 'source' => 'telegram', 'title' => $task->title, 'content' => "User sedang mengerjakan tugas: {$task->title}", 'tags' => json_encode(['interruption', 'telegram_pulse']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->telegram->sendMessage($this->chatId, "Oke silahkan lanjutkan, Semangat yaa kawan! 🥰🚀");
                $this->sessionManager->clearSession($this->chatId);
                break;
        }
    }

    private function handlePriorityChange()
    {
        preg_match('/set_prio_([a-z]+)_(\d+)/', $this->callbackData, $matches);
        if (count($matches) === 3) {
            $priority = $matches[1];
            $taskId = $matches[2];
            $task = Task::find($taskId);
            if ($task) {
                $task->update(['priority' => $priority]);
                $this->telegram->sendMessage($this->chatId, "✅ Prioritas berhasil diubah menjadi " . strtoupper($priority));
                $this->renderTaskSensation($task->id, true);
            }
        }
    }

    private function updateTaskField(string $field, string $fieldName)
    {
        $task = Task::find($this->session->active_task_id);
        if ($task) {
            $task->update([$field => $this->text]);
            $this->telegram->sendMessage($this->chatId, "✅ <b>{$fieldName} tugas berhasil diperbarui kawan!</b>");
            $this->renderTaskSensation($task->id, true);
        }
    }

    private function updateTaskDeadline()
    {
        $task = Task::find($this->session->active_task_id);
        if ($task) {
            $parsedDate = $this->parseNaturalLanguageDate($this->text);
            $task->update(['due_at' => $parsedDate]);
            $this->telegram->sendMessage($this->chatId, "🎯 JADWAL BARU DISIMPAN: <b>" . $parsedDate->format('d M Y H:i') . " WIB</b>");
            $this->renderTaskSensation($task->id, true);
        }
    }

    private function handleSubtaskInput()
    {
        $task = Task::find($this->session->active_task_id);
        if (!$task) return;

        $textLower = strtolower(trim($this->text));

        if (preg_match('/^(\d+)\s+done$/i', $textLower, $matches)) {
            $index = (int)$matches[1] - 1;
            $subtasks = DB::table('subtasks')->where('task_id', $task->id)->orderBy('position', 'asc')->get();
            if (isset($subtasks[$index])) {
                DB::table('subtasks')->where('id', $subtasks[$index]->id)->update(['done' => true]);
                $this->telegram->sendMessage($this->chatId, "👍 Status subtask nomor " . ($index + 1) . " dicentang selesai!");
                $this->renderTaskSensation($task->id, true);
            } else {
                $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan subtask tidak ditemukan kawan.");
            }
        } elseif (preg_match('/^(\d+)\s+undone$/i', $textLower, $matches)) {
            $index = (int)$matches[1] - 1;
            $subtasks = DB::table('subtasks')->where('task_id', $task->id)->orderBy('position', 'asc')->get();
            if (isset($subtasks[$index])) {
                DB::table('subtasks')->where('id', $subtasks[$index]->id)->update(['done' => false]);
                $this->telegram->sendMessage($this->chatId, "🔄 Status subtask nomor " . ($index + 1) . " diaktifkan kembali!");
                $this->renderTaskSensation($task->id, true);
            } else {
                $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan subtask tidak ditemukan kawan.");
            }
        } elseif (is_numeric($textLower)) {
            $index = (int)$textLower - 1;
            $subtasks = DB::table('subtasks')->where('task_id', $task->id)->orderBy('position', 'asc')->get();
            if (isset($subtasks[$index])) {
                $selectedSub = $subtasks[$index];
                if ($selectedSub->done) {
                    $keyboard = ['inline_keyboard' => [[['text' => 'A. iya yang kemarin belum selesai soalnya', 'callback_data' => 'sub_already_a_' . $selectedSub->id]], [['text' => 'B. ada tugas lain', 'callback_data' => 'sub_already_b_' . $selectedSub->id]]]];
                    $this->telegram->sendMessage($this->chatId, "🤔 <b>kan udah dikerjain, ada tambahan kah?</b>", ['reply_markup' => $keyboard]);
                    $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_subtask_decision']);
                } else {
                    DB::table('memories')->insert(['type' => 'task_log', 'source' => 'telegram', 'title' => $task->title, 'content' => "User sedang mengerjakan {$selectedSub->title}", 'tags' => json_encode(['task_activity', 'subtask_execute']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    $this->telegram->sendMessage($this->chatId, "Oke silahkan lanjutkan, Semangat yaa kawan! 🥰🔥");
                    $this->sessionManager->clearSession($this->chatId);
                }
            } else {
                $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan salah.");
            }
        } else {
            $cleanTitle = trim(preg_replace('/^(iya\s+)?(ada\s+)?tambahan\s+/i', '', $this->text));
            $targetTitle = $cleanTitle ?: $this->text;
            Subtask::create(['task_id' => $task->id, 'title' => $targetTitle, 'done' => false, 'position' => DB::table('subtasks')->where('task_id', $task->id)->count()]);
            DB::table('memories')->insert(['type' => 'subtask_log', 'source' => 'telegram', 'title' => $task->title, 'content' => "subtask yang baru ditambahkan: " . $targetTitle, 'tags' => json_encode(['subtask_created', 'telegram']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->telegram->sendMessage($this->chatId, "📝 <b>oke aku akan tambahkan</b> (bot membuat subtask baru).\nsilahkan lanjutkan, Semangat yaa kawan! 🚀⚡");
            $this->sessionManager->clearSession($this->chatId);
        }
    }

    private function handleSubtaskRevisit()
    {
        $subtaskId = str_replace('sub_already_a_', '', $this->callbackData);
        $subtask = Subtask::find($subtaskId);
        if ($subtask && $subtask->task) {
            DB::table('memories')->insert(['type' => 'task_log', 'source' => 'telegram', 'title' => $subtask->task->title, 'content' => "User sedang mengerjakan {$subtask->title}", 'tags' => json_encode(['task_activity', 'subtask_done_revisit']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->telegram->editMessageText($this->chatId, $this->messageId, "Oke silahkan lanjutkan, Semangat yaa kawan! 🥰🚀");
        }
        $this->sessionManager->clearSession($this->chatId);
    }

    private function handleSubtaskAddMore()
    {
        $subtaskId = str_replace('sub_already_b_', '', $this->callbackData);
        $subtask = Subtask::find($subtaskId);
        if ($subtask) {
            $this->telegram->editMessageText($this->chatId, $this->messageId, "✍️ <b>Silahkan ketik di bawah untuk menambahkan subtask baru:</b>");
            $this->sessionManager->updateSession($this->chatId, ['step' => 'waiting_subtask', 'active_task_id' => $subtask->task_id]);
        }
    }

    private function createInterruptionTask()
    {
        $duration = $this->extractDuration($this->text);
        $emergencyTask = Task::create(['user_id' => 1, 'board_id' => 2, 'title' => $this->text, 'priority' => 'urgent', 'due_at' => now()->addMinutes($duration), 'column_key' => 'todo', 'position' => 0]);

        DB::table('memories')->insert(['type' => 'interruption', 'source' => 'telegram', 'title' => mb_strimwidth($this->text, 0, 25, "..."), 'content' => "User sedang mengerjakan tugas darurat di luar jadwal: {$this->text}", 'tags' => json_encode(['interruption', 'telegram_sync']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // Note: Reminder logic might need a dedicated service or be handled by the scheduler.
        // For now, we just inform the user.

        $this->telegram->sendMessage($this->chatId, "🚀 <b>oke aku tambahin ke daftar task mu hari ini.</b>\naku ingetin / tanya {$duration} menit lagi ya udah selesai atau belum.", ['reply_markup' => json_encode(['inline_keyboard' => [[['text' => '👍 Oke', 'callback_data' => 'clear_notif']]]])]);

        $motivations = ["Semangat, lanjutin pengerjaannya kawan! 💪🔥", "Gas terus su, kerja keras takkan mengkhianati hasil! 💵🤑", "Fokus Bay, beresin itu kerjaan biar cepet santai lagi! 🚀"];
        $this->telegram->sendMessage($this->chatId, $motivations[array_rand($motivations)]);
        $this->sessionManager->clearSession($this->chatId);
    }

    private function extractDuration(string $text): int
    {
        if (preg_match('/(\d+)\s*(menit|menitan|mnt)/i', $text, $matches)) {
            return (int)$matches[1];
        }
        return 10; // Default duration
    }

    private function parseNaturalLanguageDate($string): Carbon
    {
        $string = strtolower(trim($string));
        $now = Carbon::now('Asia/Jakarta');
        $baseDate = clone $now;

        if (str_contains($string, 'besok')) {
            $baseDate->addDay();
        }
        if (preg_match('/tanggal\s*(\d{1,2})/', $string, $dayMatches)) {
            $baseDate->day(intval($dayMatches[1]));
        }

        $hour = 9;
        $minute = 0;
        if (preg_match('/(?:jam|pukul)\s*(\d{1,2})(?:[\.:](\d{2}))?/', $string, $matches)) {
            $hour = intval($matches[1]);
            $minute = isset($matches[2]) ? intval($matches[2]) : 0;
            if ((str_contains($string, 'malam') || str_contains($string, 'sore')) && $hour < 12) {
                $hour += 12;
            }
        }
        return Carbon::create($baseDate->year, $baseDate->month, $baseDate->day, $hour, $minute, 0, 'Asia/Jakarta');
    }
}
