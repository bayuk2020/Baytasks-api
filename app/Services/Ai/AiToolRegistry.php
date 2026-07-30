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
 * Full CRUD per modul (bukan cuma Create): tiap modul idealnya punya tool Read
 * (get_*) supaya AI bisa cek data yang sudah ada / menemukan ID yang benar
 * sebelum Create/Update/Delete -- lihat instruksi "wajib cek duplikat dulu"
 * di App\Telegram\Handlers\AiHandler::buildSystemPrompt().
 *
 * CARA NAMBAH TOOL BARU:
 *   1. Tulis satu method baru di sini yang return array skema (contoh: lihat
 *      method-method di bawah).
 *   2. Daftarkan method-nya di all().
 *   3. Tambah case baru di switch-case App\Telegram\Handlers\AiHandler::executeTool()
 *      yang benar-benar mengeksekusi aksinya (create/read/update/delete Task, dst).
 *      `name` di sini HARUS sama persis dengan nama case di AiHandler.
 *   4. Kalau tool-nya tipe Read, method eksekusinya HARUS me-return array data
 *      mentah (bukan string Telegram) -- lihat catatan di AiService::chat().
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
            // --- Tasks (Full CRUD) ---
            self::getTasks(),
            self::createTask(),
            self::updateTask(),
            self::deleteTask(),

            // --- Habits ---
            self::logHabit(),

            // --- Finance (Read + Create) ---
            self::getBalances(),
            self::getTransactions(),
            self::recordTransaction(),

            // --- Pengaturan Bot ---
            self::toggleSleepMode(),

            // self::saveJournal(), dst -- tinggal tambah method + daftarkan di sini.
        ];
    }

    /**
     * Modul: Tasks (Kanban board) -- READ.
     */
    public static function getTasks(): array
    {
        return [
            'name' => 'get_tasks',
            'description' => 'Membaca daftar task/tugas milik user (hasilnya termasuk field "description" '
                .'lengkap tiap task), bisa difilter berdasarkan kata kunci judul, board, atau status kolom. '
                .'WAJIB dipanggil dulu sebelum create_task (untuk cek apakah task dengan judul serupa sudah '
                .'ada, supaya tidak dobel) dan WAJIB dipanggil dulu sebelum update_task/delete_task untuk '
                .'menemukan task_id yang benar (dan, kalau mau menambah subtask, untuk mendapatkan isi '
                .'description lama yang harus dipertahankan).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Kata kunci untuk dicari di judul ATAU deskripsi task (opsional, cocok '
                            .'sebagian/mirip, query SQL LIKE). PENTING: gunakan MAKSIMAL 1 atau 2 kata kunci '
                            .'PALING UNIK saja -- misalnya kalau user bilang "edit task web LPPM registrasi '
                            .'Lewis Unimus", gunakan search="LPPM" atau search="Lewis", JANGAN kirim kalimat '
                            .'panjangnya utuh ("web LPPM registrasi Lewis Unimus") karena LIKE mencari '
                            .'kecocokan STRING BERURUTAN, jadi kalimat panjang yang urutan katanya sedikit '
                            .'beda dari judul/deskripsi asli tidak akan ketemu sama sekali. Kalau hasil '
                            .'pencarian kosong, coba lagi dengan kata kunci lain yang lebih pendek/unik '
                            .'sebelum menyimpulkan task tidak ada.',
                    ],
                    'board_id' => [
                        'type' => 'integer',
                        'description' => 'Filter board tertentu: 2=Kerjaan, 4=Personal. Kosongkan untuk semua board.',
                        'enum' => [2, 4],
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter status kolom tertentu. Kosongkan untuk semua status.',
                        'enum' => ['backlog', 'todo', 'in_progress', 'review', 'done'],
                    ],
                    'only_incomplete' => [
                        'type' => 'boolean',
                        'description' => 'true untuk hanya menampilkan task yang belum selesai. Default true.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Tasks -- CREATE.
     */
    public static function createTask(): array
    {
        return [
            'name' => 'create_task',
            'description' => 'Membuat task/tugas BARU di salah satu board. Panggil ini kalau user bilang '
                .'mau nambahin tugas, pekerjaan, atau agenda baru. WAJIB panggil get_tasks dulu untuk '
                .'memastikan belum ada task dengan judul yang sama/mirip -- kalau sudah ada, beri tahu '
                .'user dan JANGAN buat duplikatnya.',
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
     * Modul: Tasks -- UPDATE.
     */
    public static function updateTask(): array
    {
        return [
            'name' => 'update_task',
            'description' => 'Mengubah task yang SUDAH ADA (judul, deskripsi/subtask, prioritas, deadline, '
                .'atau status/kolom). WAJIB panggil get_tasks dulu untuk mendapatkan task_id yang benar '
                .'sebelum memanggil ini -- jangan pernah menebak task_id sendiri. Untuk MENAMBAH SUBTASK: '
                .'ambil field "description" dari hasil get_tasks, tambahkan baris subtask baru di bawah '
                .'teks lama (jangan hapus isi lama), lalu kirim hasil gabungannya lewat parameter description.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'ID task yang mau diubah, didapat dari hasil get_tasks.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Judul baru. Kosongkan kalau judul tidak diubah.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Deskripsi/catatan/daftar subtask baru (menggantikan seluruh isi '
                            .'description lama) -- kalau tujuannya MENAMBAH subtask, kirim gabungan description '
                            .'lama (dari get_tasks) + baris subtask baru, BUKAN cuma subtask barunya saja. '
                            .'Kosongkan parameter ini kalau description tidak diubah sama sekali.',
                    ],
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['low', 'med', 'high', 'urgent'],
                        'description' => 'Prioritas baru. Kosongkan kalau tidak diubah.',
                    ],
                    'due_at' => [
                        'type' => 'string',
                        'description' => 'Deadline baru format "YYYY-MM-DD" atau "YYYY-MM-DD HH:MM". Kosongkan kalau tidak diubah.',
                    ],
                    'column_key' => [
                        'type' => 'string',
                        'enum' => ['backlog', 'todo', 'in_progress', 'review', 'done'],
                        'description' => 'Status/kolom baru, misalnya "done" kalau user bilang tugasnya sudah selesai.',
                    ],
                ],
                'required' => ['task_id'],
            ],
        ];
    }

    /**
     * Modul: Tasks -- DELETE.
     */
    public static function deleteTask(): array
    {
        return [
            'name' => 'delete_task',
            'description' => 'Menghapus sebuah task secara permanen. WAJIB panggil get_tasks dulu untuk '
                .'memastikan task_id yang benar dan konfirmasikan ke user judul task yang dimaksud sudah '
                .'sesuai sebelum benar-benar memanggil ini.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'ID task yang mau dihapus, didapat dari hasil get_tasks.',
                    ],
                ],
                'required' => ['task_id'],
            ],
        ];
    }

    /**
     * Modul: Habits -- CREATE (tandai selesai hari ini).
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
     * Modul: Finance -- READ saldo.
     */
    public static function getBalances(): array
    {
        return [
            'name' => 'get_balances',
            'description' => 'Membaca saldo semua akun finance (bank, e-wallet, cash, trading) milik user '
                .'saat ini. Gunakan untuk menjawab pertanyaan seperti "berapa saldo aku sekarang".',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Finance -- READ riwayat transaksi.
     */
    public static function getTransactions(): array
    {
        return [
            'name' => 'get_transactions',
            'description' => 'Membaca riwayat transaksi keuangan, opsional difilter jenis/kategori/rentang '
                .'tanggal. Gunakan untuk menjawab pertanyaan seperti "berapa pengeluaran bulan ini" atau '
                .'"transaksi terakhir apa saja".',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => ['income', 'expense', 'transfer'],
                        'description' => 'Filter jenis transaksi. Kosongkan untuk semua jenis.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Filter kategori tertentu, misalnya Food atau Transport. Kosongkan untuk semua kategori.',
                    ],
                    'from_date' => [
                        'type' => 'string',
                        'description' => 'Tanggal mulai format YYYY-MM-DD. Kosongkan untuk tanpa batas awal.',
                    ],
                    'to_date' => [
                        'type' => 'string',
                        'description' => 'Tanggal akhir format YYYY-MM-DD. Kosongkan untuk tanpa batas akhir.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maksimal jumlah transaksi yang dikembalikan, default 20, maksimal 50.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Finance -- CREATE transaksi.
     */
    public static function recordTransaction(): array
    {
        return [
            'name' => 'record_transaction',
            'description' => 'Mencatat transaksi keuangan BARU (pemasukan atau pengeluaran). Panggil ini '
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

    /**
     * Modul: Pengaturan Bot -- Sleep Mode. Saat aktif, semua reminder
     * proaktif (task/habit/morning brief/nightly summary/daily briefing)
     * dibungkam sampai dinyalakan lagi -- lihat guard `is_sleeping` di
     * app/Console/Commands/*.php dan app/Services/DailyBriefing.php.
     */
    public static function toggleSleepMode(): array
    {
        return [
            'name' => 'toggle_sleep_mode',
            'description' => 'Menyalakan atau mematikan Sleep Mode. Saat Sleep Mode menyala, bot BERHENTI '
                .'mengirim semua reminder proaktif (task, habit, morning brief, nightly summary) sampai '
                .'dimatikan lagi. Panggil dengan status=true kalau user bilang mau tidur/selamat tidur, '
                .'dan status=false kalau user menyapa pagi/bilang sudah bangun.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'boolean',
                        'description' => 'true untuk mengaktifkan Sleep Mode (bungkam reminder), false untuk mematikannya (reminder normal lagi).',
                    ],
                ],
                'required' => ['status'],
            ],
        ];
    }
}
