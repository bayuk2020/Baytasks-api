<?php

namespace App\Telegram\Handlers;

use App\Services\TelegramService;
use App\Telegram\Core\TelegramSessionManager;
use App\Models\Task;
use App\Models\Subtask;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TaskHandler
{
    protected TelegramService $telegram;
    protected TelegramSessionManager $sessionManager;
    protected int $chatId;
    protected ?string $text;
    protected ?string $callbackData;
    protected ?int $messageId;
    protected $session;

    public function __construct($chatId, $text = null, $callbackData = null, $messageId = null)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->callbackData = $callbackData;
        $this->messageId = $messageId;
        $this->telegram = new TelegramService();
        $this->sessionManager = new TelegramSessionManager();

        // Ambil data session secara otomatis lewat chat_id yang dikirim controller
        $this->session = $this->sessionManager->getSession($chatId);
    }

    public function execute()
    {
        if ($this->callbackData) {
            $this->handleCallback();
        } elseif ($this->text) {
            $this->handleTextInput();
        }
    }

    private function handleCallback()
    {
        // Pencocokan menu_tasks melempar daftar board
        if ($this->callbackData === 'menu_tasks') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💻 Kerjaan (Board 2)', 'callback_data' => 'manual_board_2'],
                        ['text' => '🌱 Personal (Board 4)', 'callback_data' => 'manual_board_4']
                    ]
                ]
            ];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "📋 <b>Pilih Papan Kerja Kamu, Bay:</b>", ['reply_markup' => $keyboard]);
            return;
        }

        // Balik ke menu 4 Pilihan Utama saat tombol Kembali diklik
        if ($this->callbackData === 'pulse_back_to_menu') {
            $pulseMenu = [
                'inline_keyboard' => [
                    [['text' => '💻 Kerja', 'callback_data' => 'pulse_kerja'], ['text' => '📖 Belajar', 'callback_data' => 'pulse_belajar']],
                    [['text' => '☕ Santai', 'callback_data' => 'pulse_santai'], ['text' => '🌐 Lainnya', 'callback_data' => 'pulse_lainnya']]
                ]
            ];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "🔔 <b>Halo Bayu, saat ini Anda sedang melakukan aktivitas apa?</b>", ['reply_markup' => $pulseMenu]);
            $this->sessionManager->updateSession($this->chatId, ['step' => 'idle', 'active_task_id' => null]);
            return;
        }

        if (in_array($this->callbackData, ['pulse_kerja', 'pulse_belajar', 'pulse_lainnya'])) {
            $this->handlePulseCheck();
        } elseif ($this->callbackData === 'intercept_target_tasks') {
            $this->startWizard();
        } elseif ($this->callbackData === 'intercept_target_memories') {
            $this->promptForInterruptionTask();
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
        }
    }

    private function handleTextInput()
    {
        $textLower = strtolower(trim($this->text));

        // PENGAMAN GLOBAL: Jika user ketik /cancel atau cancel di tengah jalan
        if ($textLower === '/cancel' || $textLower === 'cancel') {
            $this->telegram->sendMessage($this->chatId, "🛑 <b>Sesi dibatalkan.</b> Bot kembali ke status standby (Idle) kawan.");
            $this->sessionManager->clearSession($this->chatId);
            return;
        }

        // PENGECEKAN INTERAKSI TASK VIA MENU SANTAI (BOTH BOARDS 2 & 4)
        if ($this->session && $this->session->step === 'waiting_task_selection') {
            $openTasks = Task::whereIn('board_id', [2, 4])->where('column_key', '!=', 'done')->get();

            // SENSOR 1: Jika user ketik format "{nomor} done"
            if (preg_match('/^(\d+)\s+done$/i', $textLower, $matches)) {
                $index = (int)$matches[1] - 1;
                if (isset($openTasks[$index])) {
                    $task = $openTasks[$index];
                    $task->update(['completed_at' => now(), 'column_key' => 'done']);
                    $this->telegram->sendMessage($this->chatId, "✅ Mantap, Bay! Tugas \"<b>" . htmlspecialchars($task->title, ENT_QUOTES, 'UTF-8') . "</b>\" ditutup via text command!");
                    $this->handlePulseCheck(); // Auto refresh list
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan tugas salah kawan.");
                }
                return;
            }

            // SENSOR 2: Jika user HANYA MENGETIK ANGKA (Buka detail menu)
            if (is_numeric($textLower)) {
                $index = (int)$textLower - 1;
                if (isset($openTasks[$index])) {
                    $task = $openTasks[$index];
                    $this->renderTaskSensation($task->id, true);
                } else {
                    $this->telegram->sendMessage($this->chatId, "⚠️ Nomor urutan tugas salah kawan.");
                }
                return;
            }

            // FALLBACK NGASAL DI LIST TUGAS SANTAI
            $this->telegram->sendMessage(
                $this->chatId,
                "⚠️ <b>Maaf kawan, aku tidak mengenali perintah itu.</b>\n\n" .
                    "💡 <i>Ketik angka urut <code>{nomor}</code> untuk buka detail & tombol manipulasi (Contoh: <code>1</code>).\n" .
                    "💡 Ketik <code>{nomor} done</code> untuk langsung menyelesaikannya.\n" .
                    "💡 Ketik <code>/cancel</code> untuk keluar menu.</i>"
            );
            return;
        }

        // ROUTING STEP AKTIF USER UTAMA (WIZARD, SUBTASK, DESCRIPTION, DEADLINE)
        if ($this->session && $this->session->step === 'task_wizard_running') {
            $this->processWizard($this->text);
            return;
        }

        if ($this->session && $this->session->step === 'waiting_desc_update') {
            $this->updateTaskField('description', 'Deskripsi');
            return;
        }

        if ($this->session && $this->session->step === 'waiting_notes_update') {
            $this->updateTaskField('notes', 'Notes');
            return;
        }

        if ($this->session && $this->session->step === 'waiting_deadline_input') {
            $this->updateTaskDeadline();
            return;
        }

        if ($this->session && $this->session->step === 'waiting_subtask') {
            $this->handleSubtaskInput();
            return;
        }

        if ($this->session && $this->session->step === 'waiting_interruption_activity_legacy') {
            $this->createInterruptionTask();
            return;
        }

        // FALLBACK BACKUP GLOBAL (JIKA INPUT ASAL DI LUAR STEP APAPUN)
        $this->telegram->sendMessage(
            $this->chatId,
            "⚠️ <b>Maaf kawan, aku tidak mengenali perintah teks itu saat ini.</b>\n\n" .
                "💡 <i>Gunakan menu tombol di layar navigasi bot Telegram Anda untuk mengelola data, atau ketik <code>/start</code> untuk memanggil ulang Pusat Kendali BayTasks, Bay!</i>"
        );
    }

    private function handlePulseCheck()
    {
        $activity = str_replace('pulse_', '', $this->callbackData) ?: 'kerja';

        // JIKA AKTIVITAS ADALAH KERJA / BELAJAR
        if ($activity === 'kerja' || $activity === 'belajar') {
            $targetBoard = ($activity === 'belajar') ? 4 : 2;
            $label = ($activity === 'belajar') ? 'belajar/kuliah' : 'kerjaan';
            $openTasks = Task::where('board_id', $targetBoard)->where('column_key', '!=', 'done')->get();

            if ($openTasks->isEmpty()) {
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🌐 Aktivitas Lainnya', 'callback_data' => 'pulse_lainnya'],
                            ['text' => '🔙 Kembali', 'callback_data' => 'pulse_back_to_menu']
                        ]
                    ]
                ];
                $this->telegram->editMessageText($this->chatId, $this->messageId, "🎉 <b>All Clear, Bay!</b> Gak ada tugas nunggak di board {$label}mu.", ['reply_markup' => $keyboard]);
                return;
            }

            $buttons = [];
            $txt = "📋 <b>Daftar Sisa Tugas Hari Ini (" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "):</b>\n\n";
            
            foreach ($openTasks as $i => $t) {
                $txt .= ($i + 1) . ". 📌 <b>" . htmlspecialchars($t->title, ENT_QUOTES, 'UTF-8') . "</b>\n";
                $buttons[] = [['text' => "🎯 Kelola Tugas " . ($i + 1), 'callback_data' => 'select_task_' . $t->id]];
            }
            
            $txt .= "\n💡 <i>Ketik angka urut (contoh: <code>1</code>) atau klik tombol di bawah untuk mengelola detail & aksi tugas.\n" .
                    "💡 Ketik <code>{nomor} done</code> untuk menutup tugas langsung.\n" .
                    "💡 Ketik <code>/cancel</code> jika ingin membatalkan.</i>";

            $buttons[] = [
                ['text' => '🌐 Aktivitas Lainnya', 'callback_data' => 'pulse_lainnya'],
                ['text' => '🔙 Kembali', 'callback_data' => 'pulse_back_to_menu']
            ];

            $this->sessionManager->updateSession($this->chatId, [
                'step' => 'waiting_task_selection', 
                'context_data' => json_encode(['activity_type' => $activity])
            ]);
            
            $this->telegram->editMessageText($this->chatId, $this->messageId, $txt, [
                'reply_markup' => ['inline_keyboard' => $buttons]
            ]);
        } 
        // JIKA USER TENTUKAN PILIHAN AKTIVITAS LAINNYA
        elseif ($activity === 'lainnya') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🗂️ Masuk Memories', 'callback_data' => 'intercept_target_memories'], 
                        ['text' => '📋 Masuk Tasks', 'callback_data' => 'intercept_target_tasks']
                    ]
                ]
            ];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "🌐 <b>Silakan ketik aktivitas Anda saat ini secara manual:</b>\n\nSebelum lanjut, tentukan ke mana sistem harus mengarsipkan aktivitas baru ini, Bay?", ['reply_markup' => $keyboard]);
        }
    }

        $buttons = [];
        $txt = "📋 <b>Daftar Sisa Tugas Hari Ini (" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "):</b>\n\n";

        foreach ($openTasks as $i => $t) {
            $txt .= ($i + 1) . ". 📌 <b>" . htmlspecialchars($t->title, ENT_QUOTES, 'UTF-8') . "</b>\n";
            // FIX TERPENTING: Inject tombol inline dinamis untuk tiap tugas biar langsung bisa dieksekusi!
            $buttons[] = [['text' => "🎯 Kelola Tugas " . ($i + 1), 'callback_data' => 'select_task_' . $t->id]];
        }

        // UX IMPROVEMENT: Informasi instruksi pengetikan text di bagian paling bawah teks pesan
        $txt .= "\n💡 <i>Ketik angka urut (contoh: <code>1</code>) atau klik tombol di bawah untuk mengelola detail & aksi tugas.\n" .
            "💡 Ketik <code>{nomor} done</code> untuk menutup tugas langsung.\n" .
            "💡 Ketik <code>/cancel</code> jika ingin membatalkan.</i>";

        $buttons[] = [
            ['text' => '🌐 Aktivitas Lainnya', 'callback_data' => 'pulse_lainnya'],
            ['text' => '🔙 Kembali', 'callback_data' => 'pulse_back_to_menu']
        ];

        $this->sessionManager->updateSession($this->chatId, [
            'step' => 'waiting_task_selection',
            'context_data' => json_encode(['activity_type' => $activity])
        ]);

        $this->telegram->editMessageText($this->chatId, $this->messageId, $txt, [
            'reply_markup' => ['inline_keyboard' => $buttons]
        ]);
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
                $keyboard = ['inline_keyboard' => [
                    [['text' => 'Backlog', 'callback_data' => 'wiz_status_backlog'], ['text' => 'To Do', 'callback_data' => 'wiz_status_todo'], ['text' => 'In Progress', 'callback_data' => 'wiz_status_in_progress']],
                    [['text' => 'Review', 'callback_data' => 'wiz_status_review'], ['text' => 'Done', 'callback_data' => 'wiz_status_done']]
                ]];
                $this->telegram->sendMessage($this->chatId, "<b>[Step 5 / 10] Status?</b>", ['reply_markup' => $keyboard]);
                break;
            case 'wizard_status':
                $statusMap = ['wiz_status_backlog' => 'backlog', 'wiz_status_todo' => 'todo', 'wiz_status_in_progress' => 'in_progress', 'wiz_status_review' => 'review', 'wiz_status_done' => 'done'];
                $state['data']['column_key'] = $statusMap[$input] ?? 'todo';
                $state['current_step'] = 'wizard_priority';
                $keyboard = ['inline_keyboard' => [[['text' => 'LOW', 'callback_data' => 'wiz_prio_low'], ['text' => 'MED', 'callback_data' => 'wiz_prio_med']], [['text' => 'HIGH', 'callback_data' => 'wiz_prio_high'], ['text' => 'URGENT', 'callback_data' => 'wiz_prio_urgent']]]];
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
                    'board_id' => 2,
                    'user_id' => 1,
                    'title' => $state['data']['title'],
                    'description' => $state['data']['description'] ?? null,
                    'notes' => $state['data']['notes'] ?? null,
                    'column_key' => $state['data']['column_key'] ?? 'todo',
                    'priority' => $state['data']['priority'] ?? 'med',
                    'tags' => $tagsArray,
                    'due_at' => $state['data']['due_at'] ?? null,
                    'reminder' => $state['data']['reminder'] ?? null,
                    'recurring' => $state['data']['recurring'] ?? 'none',
                    'position' => 0,
                    'reminded' => false
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
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 Kembali ke Menu Board', 'callback_data' => 'menu_tasks']]]];
            $this->telegram->editMessageText($this->chatId, $this->messageId, "🎉 Bersih, Bay! Gak ada tugas nunggak di board ini.", ['reply_markup' => $keyboard]);
            return;
        }

        $buttons = [];
        $txt = "📋 <b>Daftar Sisa Tugas Hari Ini (Board {$boardId}):</b>\n\n";

        foreach ($openTasks as $i => $t) {
            $txt .= ($i + 1) . ". 📌 <b>" . htmlspecialchars($t->title, ENT_QUOTES, 'UTF-8') . "</b>\n";
            $buttons[] = [['text' => "🎯 Kelola Tugas " . ($i + 1), 'callback_data' => 'select_task_' . $t->id]];
        }

        $buttons[] = [['text' => '🔙 Kembali ke Menu Board', 'callback_data' => 'menu_tasks']];

        $this->telegram->editMessageText($this->chatId, $this->messageId, $txt, [
            'reply_markup' => ['inline_keyboard' => $buttons]
        ]);
    }

    public function renderTaskSensation(int $taskId, bool $isNewMessage = false)
    {
        $task = Task::find($taskId);
        if (!$task) return;

        // 1. Mapping Emoji untuk Priority
        $priorityEmojis = [
            'low'    => '🟢 Low',
            'med'    => '🟡 Medium',
            'high'   => '🟠 High',
            'urgent' => '🔴 Urgent'
        ];
        $priorityText = $priorityEmojis[strtolower($task->priority)] ?? '⚪ ' . ($task->priority ?? '-');

        // 2. Formatting Due Date (Membaca dari cast datetime)
        $dueDateText = $task->due_at ? $task->due_at->format('d M Y H:i') : '-';

        $subtasks = DB::table('subtasks')->where('task_id', $task->id)->orderBy('position', 'asc')->get();
        $subtext = "";
        if ($subtasks->isEmpty()) {
            $subtext = "<i>(Belum ada subtask)</i>\n";
        } else {
            foreach ($subtasks as $i => $sub) {
                $num = $i + 1;
                $subtext .= $sub->done ? "✅ {$num}. {$sub->title} (dicoret)\n" : "❌ {$num}. {$sub->title} (belum dikerjakan)\n";
            }
        }

        // 3. Menyusun Pesan dengan tambahan Priority dan Due Date di bawah Task
        $message = "🎯 <b>Sesi Fokus Tugas Atraktif</b>\n\n" .
            "• <b>Task:</b> {$task->title}\n" .
            "• <b>Priority:</b> {$priorityText}\n" .
            "• <b>Due Date:</b> {$dueDateText}\n" .
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
                [['text' => '🔙 Ganti Tugas Lain', 'callback_data' => 'action_back_' . $task->id]],
                [['text' => '🏠 Menu Utama (Aktivitas)', 'callback_data' => 'pulse_back_to_menu']]
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
                $this->telegram->sendMessage($this->chatId, "📅 <b>Ketik jadwal deadline baru kawan:</b>");
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
                DB::table('memories')->insert(['type' => 'task_log', 'source' => 'telegram', 'title' => $task->title, 'content' => "User sedang mengerjakan tugas: {$task->title}", 'tags' => json_encode(['task_activity', 'direct_execute']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
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
            }
            return;
        }

        if (preg_match('/^(\d+)\s+undone$/i', $textLower, $matches)) {
            $index = (int)$matches[1] - 1;
            $subtasks = DB::table('subtasks')->where('task_id', $task->id)->orderBy('position', 'asc')->get();
            if (isset($subtasks[$index])) {
                DB::table('subtasks')->where('id', $subtasks[$index]->id)->update(['done' => false]);
                $this->telegram->sendMessage($this->chatId, "🔄 Status subtask nomor " . ($index + 1) . " diaktifkan kembali!");
                $this->renderTaskSensation($task->id, true);
            }
            return;
        }

        if (is_numeric($textLower)) {
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
            }
            return;
        }

        $cleanTitle = trim(preg_replace('/^(iya\s+)?(ada\s+)?tambahan\s+/i', '', $this->text));
        $targetTitle = $cleanTitle ?: $this->text;
        Subtask::create(['task_id' => $task->id, 'title' => $targetTitle, 'done' => false, 'position' => DB::table('subtasks')->where('task_id', $task->id)->count()]);
        DB::table('memories')->insert(['type' => 'subtask_log', 'source' => 'telegram', 'title' => $task->title, 'content' => "subtask yang baru ditambahkan: " . $targetTitle, 'tags' => json_encode(['subtask_created', 'telegram']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->telegram->sendMessage($this->chatId, "📝 <b>oke aku akan tambahkan</b> (bot membuat subtask baru).\nsilahkan lanjutkan, Semangat yaa kawan! 🚀⚡");
        $this->sessionManager->clearSession($this->chatId);
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

        DB::table('reminders')->insert([
            'task_id' => $emergencyTask->id,
            'fire_at' => now()->addMinutes($duration),
            'channel' => 'telegram',
            'sent' => 0
        ]);

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
        return 10;
    }

    private function parseNaturalLanguageDate($string)
    {
        $string = strtolower(trim($string));

        // Normalisasi: Ubah tanda titik (.) yang mengapit angka jam menjadi titik dua (:) 
        // Contoh: "20 july 2026 10.00" -> "20 july 2026 10:00"
        $normalizedString = preg_replace('/(\d{1,2})\.(\d{2})/', '$1:$2', $string);

        try {
            // Coba biarkan Carbon yang memproses format standar terlebih dahulu
            // (contoh: "20 July 2026 10:00", "tomorrow 15:00", "next monday")
            $date = Carbon::parse($normalizedString, 'Asia/Jakarta');

            // Jika user hanya ngetik "20 July 2026" tanpa jam, defaultkan ke jam 23:59 (akhir hari)
            if (!preg_match('/(\d{1,2})[:.](\d{2})/', $normalizedString)) {
                $date->setTime(23, 59, 0);
            }

            return $date;
        } catch (\Exception $e) {
            // JIKA GAGAL: Masuk ke regex bahasa Indonesia manual Anda
            $now = Carbon::now('Asia/Jakarta');
            $baseDate = clone $now;

            if (str_contains($string, 'besok')) $baseDate->addDay();
            if (str_contains($string, 'lusa')) $baseDate->addDays(2);

            // Cek jika ada kata "tanggal X"
            if (preg_match('/tanggal\s*(\d{1,2})/', $string, $dayMatches)) {
                $baseDate->day((int)$dayMatches[1]);
            }

            $hour = 23; // Default jam akhir hari jika tidak disebutkan
            $minute = 59;

            // Deteksi jam dengan variasi "jam X", "pukul X", atau angka mentah "XX.XX" di akhir
            if (
                preg_match('/(?:jam|pukul)?\s*(\d{1,2})[:\.](\d{2})/', $string, $matches) ||
                preg_match('/(?:jam|pukul)\s*(\d{1,2})/', $string, $matches)
            ) {

                $hour = (int)$matches[1];
                $minute = isset($matches[2]) ? (int)$matches[2] : 0;

                // Konversi ke format 24 jam jika ada konteks waktu sore/malam
                if ((str_contains($string, 'malam') || str_contains($string, 'sore')) && $hour < 12) {
                    $hour += 12;
                }
            }

            return Carbon::create($baseDate->year, $baseDate->month, $baseDate->day, $hour, $minute, 0, 'Asia/Jakarta');
        }
    }
}
