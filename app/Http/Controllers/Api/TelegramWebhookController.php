<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Telegram\Handlers\TaskHandler;
use App\Telegram\Handlers\HabitHandler;
use App\Telegram\Handlers\AiHandler;
use App\Models\Story;
use App\Models\Task; // Wajib ditambahkan untuk memanggil model Task

class TelegramWebhookController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function handle(Request $request)
    {
        $update = $request->all();

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }

        return response()->json(['status' => 'success']);
    }

    private function handleMessage($message)
    {
        $chatId = $message['chat']['id'];

        // Pesan foto (dengan/tanpa caption) DITANGANI DULU, sebelum guard
        // `empty($text)` di bawah -- pesan foto dari Telegram tidak punya field
        // `text` sama sekali (captionnya ada di `caption`), jadi kalau dibiarkan
        // lewat guard itu, pesan foto akan selalu ke-skip diam-diam.
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $this->handlePhotoMessage($chatId, $message);
            return;
        }

        $text = $message['text'] ?? '';

        // Pesan teks biasa (termasuk "ping") TIDAK disentuh di sini sama sekali --
        // tetap lanjut ke routing lama di bawah, yang pada akhirnya melempar ke
        // AiHandler kalau sesi sedang idle.
        if (empty($text)) return;

        $textLower = strtolower(trim($text));

        // PENGAMAN 1: Global Cancel Handler (Biar sesi reset ke idle kapan pun lu ketik /cancel)
        if ($textLower === '/cancel') {
            DB::table('telegram_sessions')->updateOrInsert(
                ['chat_id' => $chatId],
                ['step' => 'idle', 'active_task_id' => null, 'form_state' => null, 'context_data' => null, 'updated_at' => now()]
            );
            $this->telegram->sendMessage($chatId, "🛑 <b>Sesi berhasil dibatalkan.</b> Bot kembali ke status standby (Idle).");
            return;
        }

        // Global Command /start
        if ($textLower === '/start') {
            DB::table('telegram_sessions')->updateOrInsert(
                ['chat_id' => $chatId],
                ['step' => 'idle', 'active_task_id' => null, 'form_state' => null, 'context_data' => null, 'updated_at' => now()]
            );

            $menuMarkup = [
                'inline_keyboard' => [
                    [
                        ['text' => '📋 Tasks', 'callback_data' => 'menu_tasks'],
                        ['text' => '🔁 Habits', 'callback_data' => 'menu_habits']
                    ],
                    [
                        ['text' => '☕ Leisure Dashboard', 'callback_data' => 'pulse_santai']
                    ]
                ]
            ];

            $this->telegram->sendMessage($chatId, "👋 <b>Halo Bayu! Selamat datang kembali di Pusat Kendali Aktivitas Anda.</b>\n\nSilakan pilih menu di bawah ini untuk memulai:", [
                'reply_markup' => json_encode($menuMarkup)
            ]);
            return;
        }

        // Ambil session saat ini
        $session = DB::table('telegram_sessions')->where('chat_id', $chatId)->first();
        $step = $session->step ?? 'idle';

        // Tentukan Prefixes untuk Message Text
        $taskPrefixes = ['form_', 'waiting_', 'wiz_', 'manual_board_', 'select_task_', 'action_', 'set_prio_', 'sub_', 'intercept_target_', 'task_wizard_running'];
        $habitPrefixes = ['habit_', 'menu_habits', 'leisure_'];

        if (Str::is($taskPrefixes, $step) || $step === 'task_wizard_running') {
            (new TaskHandler($chatId, $text, null, null))->execute();
        } elseif (Str::is($habitPrefixes, $step) || str_contains($step, 'leisure')) {
            (new HabitHandler($chatId, $text, null, null))->execute();
        } elseif ($step === 'idle') {
            // Sesi benar-benar nganggur & teksnya bukan command dikenal -> lempar ke AI
            // biar bisa ngerti chat bebas & manggil tools (create_task, log_habit, dst).
            (new AiHandler($chatId, $text))->execute();
        } else {
            // Step tidak dikenal (kemungkinan sisa sesi lama) -> fallback ke perilaku lama.
            (new TaskHandler($chatId, $text, null, null))->execute();
        }
    }

    /**
     * Tangkap foto yang dikirim user ke bot, unduh lewat Telegram Bot API
     * (getFile -> download), simpan ke storage/app/public/stories, lalu catat
     * sebagai satu Story baru (dengan caption-nya kalau ada).
     */
    private function handlePhotoMessage($chatId, array $message): void
    {
        $sizes = $message['photo'];
        // Array `photo` diurutkan dari resolusi terkecil ke terbesar --
        // elemen TERAKHIR adalah resolusi tertinggi yang tersedia.
        $largest = end($sizes);
        $fileId = $largest['file_id'] ?? null;
        $caption = $message['caption'] ?? null;

        if (!$fileId) {
            Log::warning('[TelegramWebhook] Pesan foto tanpa file_id yang valid.', ['message' => $message]);
            $this->telegram->sendMessage($chatId, '⚠️ Gagal membaca foto yang dikirim.');
            return;
        }

        try {
            $token = env('TELEGRAM_BOT_TOKEN');

            // 1. Minta lokasi file asli dari Telegram lewat endpoint getFile.
            $fileInfo = Http::get("https://api.telegram.org/bot{$token}/getFile", [
                'file_id' => $fileId,
            ])->json();

            $filePath = $fileInfo['result']['file_path'] ?? null;

            if (!$filePath) {
                throw new \RuntimeException('Telegram getFile tidak mengembalikan file_path: ' . json_encode($fileInfo));
            }

            // 2. Download file gambar aslinya dari server file Telegram.
            $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
            $imageResponse = Http::get($downloadUrl);

            if ($imageResponse->failed()) {
                throw new \RuntimeException("Gagal mengunduh file dari Telegram (HTTP {$imageResponse->status()}).");
            }

            // 3. Simpan ke disk public (storage/app/public/stories -> public/storage/stories).
            $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'stories/' . Str::uuid() . '.' . $extension;
            Storage::disk('public')->put($filename, $imageResponse->body());

            // 4. Catat sebagai Story baru.
            Story::create([
                'image_path' => $filename,
                'caption' => $caption,
            ]);

            Log::info('[TelegramWebhook] Story baru tersimpan dari foto Telegram.', ['image_path' => $filename]);
            $this->telegram->sendMessage($chatId, '📸 Sip, foto sudah aku simpan ke Story kamu!');
        } catch (\Throwable $e) {
            Log::error('[TelegramWebhook] Gagal memproses foto: ' . $e->getMessage());
            $this->telegram->sendMessage($chatId, "⚠️ Gagal menyimpan foto: {$e->getMessage()}");
        }
    }

    private function handleCallback($callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $callbackData = $callbackQuery['data'];

        // =========================================================
        // 🔥 INTERCEPTOR: TANGKAP TOMBOL DARI PENGINGAT TASK DULU
        // =========================================================

        // JIKA TOMBOL "SUDAH" DIKLIK
        if (str_starts_with($callbackData, 'task_done_')) {
            $taskId = str_replace('task_done_', '', $callbackData);
            $task = Task::find($taskId);

            if ($task) {
                $task->update([
                    'completed_at' => now(),
                    'reminded' => true
                ]);
                $this->telegram->editMessageText($chatId, $messageId, "✅ Sip! Tugas <b>{$task->title}</b> sudah berhasil ditandai selesai. Mantap kerjanya Bay!");
            } else {
                $this->telegram->editMessageText($chatId, $messageId, "⚠️ Oops, data tugas tidak ditemukan di database.");
            }
            return; // Hentikan script di sini agar tidak dilanjutkan ke TaskHandler
        }

        // JIKA TOMBOL "BELUM" DIKLIK
        if (str_starts_with($callbackData, 'task_notdone_')) {
            $taskId = str_replace('task_notdone_', '', $callbackData);
            $task = Task::find($taskId);

            if ($task) {
                $this->telegram->editMessageText($chatId, $messageId, "❌ Oke, tugas <b>{$task->title}</b> masih gantung. Jangan lupa diselesaikan ya!");
            } else {
                $this->telegram->editMessageText($chatId, $messageId, "⚠️ Oops, data tugas tidak ditemukan di database.");
            }
            return; // Hentikan script di sini
        }

        // =========================================================
        // FIX ROUTING (BALIK KE LOGIKA LAMA)
        // =========================================================
        if (
            Str::startsWith($callbackData, ['menu_tasks', 'task_', 'waiting_', 'wiz_', 'manual_board_', 'select_task_', 'action_', 'set_prio_', 'sub_', 'intercept_target_']) ||
            str_contains($callbackData, 'pulse_kerja') ||
            str_contains($callbackData, 'pulse_belajar') ||
            str_contains($callbackData, 'pulse_lainnya') ||
            str_contains($callbackData, 'pulse_back_to_menu')
        ) {
            (new TaskHandler($chatId, null, $callbackData, $messageId))->execute();
        } elseif (Str::startsWith($callbackData, ['menu_habits', 'habit_']) || str_contains($callbackData, 'pulse_santai') || str_contains($callbackData, 'leisure_')) {
            (new HabitHandler($chatId, null, $callbackData, $messageId))->execute();
        }
    }
}
