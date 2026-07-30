<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Memory;
use App\Services\Ai\AiToolExecutor;
use App\Services\Ai\AiToolRegistry;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Endpoint chat untuk widget AI di web (FloatingAiChat.tsx). Memanggil
 * AiService + AiToolRegistry + AiToolExecutor yang SAMA PERSIS dipakai bot
 * Telegram (App\Telegram\Handlers\AiHandler) -- bukan implementasi tandingan,
 * cuma beda "pembungkus" input/output (HTTP JSON di sini, pesan Telegram di sana).
 */
class AiWebController extends Controller
{
    private const MEMORY_CONTEXT_LIMIT = 5;

    private const MAX_TOOL_ITERATIONS = 5;

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            // Riwayat percakapan sebelumnya dari widget (opsional) -- API ini
            // stateless, jadi frontend yang menyimpan & mengirim ulang history-nya
            // tiap request, persis pola FormModal lain di app ini yang selalu
            // mengirim payload lengkap alih-alih mengandalkan sesi server.
            'history' => ['nullable', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt(AiToolExecutor::memoryContext(self::MEMORY_CONTEXT_LIMIT))],
        ];

        foreach ($validated['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $validated['message']];

        try {
            $result = (new AiService())->chat(
                $messages,
                AiToolRegistry::all(),
                fn (string $name, array $args) => (new AiToolExecutor())->execute($name, $args),
                self::MAX_TOOL_ITERATIONS
            );
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        $reply = ($result['content'] ?? '') !== '' ? $result['content'] : 'Maaf, aku belum kepikiran balasan yang pas untuk itu.';

        Memory::create([
            'type' => 'ai_chat',
            'source' => 'web_ai_chat',
            'title' => 'Percakapan AI (Web)',
            'content' => Str::limit($validated['message'].' -> '.$reply, 500),
            'occurred_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'reply' => $reply,
            'provider' => $result['provider'] ?? null,
        ]);
    }

    private function buildSystemPrompt(string $memoryContext): string
    {
        $memoryLimit = self::MEMORY_CONTEXT_LIMIT;

        return <<<PROMPT
            Kamu adalah asisten pribadi Bayu di widget chat web aplikasi "BayTasks" yang
            mengurus Task, Habit, Finance, Goals, dan Journal miliknya. Jawab dengan Bahasa
            Indonesia yang santai tapi jelas dan ringkas.

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

            SLEEP MODE: kalau user mengucapkan "selamat tidur"/"mau tidur", panggil tool
            toggle_sleep_mode(status=true). Kalau user menyapa pagi/"sudah bangun", panggil
            toggle_sleep_mode(status=false). Selalu konfirmasi ke user dengan kalimat hangat
            setelah tool ini dipanggil.

            Konteks {$memoryLimit} aktivitas terakhir (dari tabel memories):
            {$memoryContext}
            PROMPT;
    }
}
