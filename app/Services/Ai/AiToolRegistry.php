<?php

namespace App\Services\Ai;

/**
 * Pusat pendaftaran "tools" (function calling) yang boleh dipanggil AI.
 *
 * Setiap tool ditulis dalam SATU format kanonis (mirip JSON Schema ala OpenAI:
 * name + description + parameters). App\Services\AiService yang menerjemahkan
 * format ini ke bentuk masing-masing provider (OpenAI-compatible `tools`,
 * atau `functionDeclarations` Gemini) — jadi definisi tool di sini tidak perlu
 * peduli provider mana yang akhirnya dipakai.
 *
 * CARA NAMBAH TOOL BARU:
 *   1. Tulis satu method baru di sini yang return array skema (contoh: lihat
 *      createTask()/logHabit()/recordTransaction() di bawah).
 *   2. Daftarkan method-nya di all().
 *   3. Tambah case baru di switch-case App\Telegram\Handlers\AiHandler::handleFunctionCall()
 *      yang benar-benar mengeksekusi aksinya (create Task, update Habit, dst).
 *   `name` di sini HARUS sama persis dengan nama case di AiHandler.
 */
class AiToolRegistry
{
    /**
     * Semua tool yang aktif & dikirim ke AI. Tambah/kurangi daftar ini untuk
     * mengatur modul mana saja yang boleh diakses AI lewat chat bebas.
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public static function all(): array
    {
        return [
            self::createTask(),
            self::logHabit(),
            self::recordTransaction(),
            // self::saveJournal(), dst -- tinggal tambah method + daftarkan di sini.
        ];
    }

    /**
     * Modul: Tasks (Kanban board).
     */
    public static function createTask(): array
    {
        return [
            'name' => 'create_task',
            'description' => 'Membuat task/tugas baru di salah satu board. Panggil ini kalau user '
                .'bilang mau nambahin tugas, pekerjaan, atau agenda baru yang perlu dikerjakan.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Judul singkat & jelas untuk tugasnya.',
                    ],
                    'board_id' => [
                        'type' => 'integer',
                        'description' => 'ID board tujuan: 2 untuk Kerjaan, 4 untuk Personal.',
                        'enum' => [2, 4],
                    ],
                    'priority' => [
                        'type' => 'string',
                        'description' => 'Prioritas tugas. Default "med" kalau user tidak menyebutkan.',
                        'enum' => ['low', 'med', 'high', 'urgent'],
                    ],
                    'due_at' => [
                        'type' => 'string',
                        'description' => 'Deadline dalam format "YYYY-MM-DD" atau "YYYY-MM-DD HH:MM". '
                            .'Kosongkan kalau user tidak menyebut tenggat waktu.',
                    ],
                ],
                'required' => ['title', 'board_id'],
            ],
        ];
    }

    /**
     * Modul: Habits.
     */
    public static function logHabit(): array
    {
        return [
            'name' => 'log_habit',
            'description' => 'Menandai sebuah habit sudah dikerjakan HARI INI. Panggil ini kalau user '
                .'bilang sudah melakukan suatu kebiasaan rutin (olahraga, baca buku, minum air, dsb).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'habit_title' => [
                        'type' => 'string',
                        'description' => 'Nama habit sesuai yang diucapkan user (dipakai untuk mencari '
                            .'habit yang cocok di daftar habit milik user, pencocokan tidak harus persis sama).',
                    ],
                    'notes' => [
                        'type' => 'string',
                        'description' => 'Catatan tambahan opsional tentang progress hari ini.',
                    ],
                ],
                'required' => ['habit_title'],
            ],
        ];
    }

    /**
     * Modul: Finance.
     */
    public static function recordTransaction(): array
    {
        return [
            'name' => 'record_transaction',
            'description' => 'Mencatat transaksi keuangan baru (pemasukan atau pengeluaran). Panggil ini '
                .'kalau user cerita habis belanja/bayar sesuatu, atau baru saja menerima uang.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Jenis transaksi.',
                        'enum' => ['income', 'expense'],
                    ],
                    'amount' => [
                        'type' => 'number',
                        'description' => 'Nominal uang dalam Rupiah (angka polos, tanpa titik/koma pemisah ribuan).',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Kategori transaksi, misalnya Food, Transport, Bills, Salary.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Keterangan singkat opsional tentang transaksinya.',
                    ],
                ],
                'required' => ['type', 'amount', 'category'],
            ],
        ];
    }
}
