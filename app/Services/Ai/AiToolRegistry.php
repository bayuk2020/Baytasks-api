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

            // --- Subtasks (checklist di dalam task) ---
            self::addSubtasks(),
            self::completeSubtask(),
            self::deleteSubtask(),

            // --- Habits ---
            self::getHabits(),
            self::createHabit(),
            self::logHabit(),

            // --- Finance: kategori, akun & transaksi ---
            self::getFinanceCategories(),
            self::getBalances(),
            self::createAccount(),
            self::getTransactions(),
            self::recordTransaction(),

            // --- Finance: kontak, budget, utang, analitik ---
            self::getContacts(),
            self::createContact(),
            self::getBudgets(),
            self::createBudget(),
            self::getDebts(),
            self::createDebt(),
            self::recordDebtPayment(),
            self::getAnalytics(),

            // --- Pomodoro / Focus ---
            self::startFocusSession(),
            self::stopFocusSession(),
            self::logFocusSession(),
            self::getFocusStats(),

            // --- Journal & Story ---
            self::getJournals(),
            self::createJournal(),
            self::createStory(),

            // --- Pengaturan Bot ---
            self::toggleSleepMode(),
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
                    'description' => [
                        'type' => 'string',
                        'description' => 'Deskripsi/konteks lengkap tugasnya. ISI INI kalau user menceritakan '
                            .'detail, angka, nama orang, atau latar belakang -- jangan dibuang. Contoh: user '
                            .'bilang "cek laptop Mbak Pipit, kata Mas Sus coba install HDD Sentinel buat cek '
                            .'kesehatan SSD" -> title="Mengecek Laptop Mbak Pipit", description="Kata Mas Sus: '
                            .'coba install HDD Sentinel untuk cek kesehatan SSD". Rincian bertahap yang harus '
                            .'dikerjakan satu per satu tetap masuk add_subtasks, tapi konteks/nominal/nama '
                            .'orang taruh di sini.',
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
            'description' => 'Mengubah task yang SUDAH ADA (judul, deskripsi, prioritas, deadline, atau '
                .'status/kolom). WAJIB panggil get_tasks dulu untuk mendapatkan task_id yang benar sebelum '
                .'memanggil ini -- jangan pernah menebak task_id sendiri. CATATAN: untuk menambah SUBTASK/'
                .'checklist, JANGAN pakai tool ini -- pakai add_subtasks.',
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
                        'description' => 'Deskripsi/konteks baru. Nilai ini MENGGANTIKAN seluruh isi description '
                            .'lama, jadi kalau maksudnya cuma menambah, ambil dulu description lama dari '
                            .'get_tasks lalu kirim gabungannya. Kosongkan kalau description tidak diubah. '
                            .'JANGAN dipakai untuk menaruh daftar subtask -- itu tugas add_subtasks.',
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
     * Modul: Subtasks -- CREATE (bisa banyak sekaligus).
     */
    public static function addSubtasks(): array
    {
        return [
            'name' => 'add_subtasks',
            'description' => 'Menambahkan satu atau BANYAK subtask (item checklist) ke dalam task yang SUDAH ADA. '
                .'INI SATU-SATUNYA cara yang benar untuk menambah subtask -- JANGAN PERNAH menulis daftar '
                .'subtask ke dalam parameter description milik update_task, karena subtask disimpan sebagai '
                .'baris tersendiri yang bisa dicentang di aplikasi, bukan sebagai teks biasa. WAJIB panggil '
                .'get_tasks dulu (pakai 1-2 kata kunci) untuk mendapatkan task_id yang benar.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'ID task induk, didapat dari hasil get_tasks.',
                    ],
                    'titles' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Daftar judul subtask yang mau ditambahkan. Kalau user menyebut '
                            .'beberapa poin bernomor, masukkan SEMUANYA sekaligus dalam satu array ini '
                            .'(cukup satu kali panggil tool), mis. ["Rancang web sesuai design", '
                            .'"Masukkan konten video sesuai tema", "Follow up Pak Sayono"]. Tulis tiap poin '
                            .'apa adanya sesuai kata user, tanpa nomor urut di depannya.',
                    ],
                ],
                'required' => ['task_id', 'titles'],
            ],
        ];
    }

    /**
     * Modul: Subtasks -- centang/batal-centang.
     */
    public static function completeSubtask(): array
    {
        return [
            'name' => 'complete_subtask',
            'description' => 'Menandai satu subtask sebagai SELESAI (atau membatalkan centangnya). WAJIB panggil '
                .'get_tasks dulu untuk mendapatkan subtask_id yang benar -- hasil get_tasks sudah memuat daftar '
                .'subtask tiap task beserta id-nya.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'subtask_id' => [
                        'type' => 'integer',
                        'description' => 'ID subtask, didapat dari field subtasks[].id di hasil get_tasks.',
                    ],
                    'done' => [
                        'type' => 'boolean',
                        'description' => 'true untuk menandai selesai (default), false untuk membatalkan centang.',
                    ],
                ],
                'required' => ['subtask_id'],
            ],
        ];
    }

    /**
     * Modul: Subtasks -- DELETE.
     */
    public static function deleteSubtask(): array
    {
        return [
            'name' => 'delete_subtask',
            'description' => 'Menghapus satu subtask dari sebuah task. WAJIB panggil get_tasks dulu untuk '
                .'mendapatkan subtask_id yang benar, dan pastikan ke user subtask yang dimaksud sudah sesuai.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'subtask_id' => [
                        'type' => 'integer',
                        'description' => 'ID subtask yang mau dihapus, dari field subtasks[].id di hasil get_tasks.',
                    ],
                ],
                'required' => ['subtask_id'],
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
                .'kalau user cerita habis belanja/bayar sesuatu, atau baru saja menerima uang. WAJIB panggil '
                .'get_finance_categories DULU untuk tahu daftar kategori resmi, karena parameter category '
                .'HANYA boleh diisi salah satu nama dari daftar itu.',
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
                        'description' => 'Nominal uang dalam Rupiah (angka polos, tanpa titik/koma pemisah ribuan). '
                            .'Contoh: user bilang "18.000" atau "18rb" -> kirim 18000.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'WAJIB DIISI. Apa barang/jasa yang dibeli, atau dari mana uangnya datang -- '
                            .'ditulis apa adanya sesuai kata user. CONTOH BENAR: user bilang "aku habis beli rokok '
                            .'18.000" -> description="Beli rokok". User bilang "dapat gaji 5 juta" -> '
                            .'description="Gaji bulanan". JANGAN PERNAH mengosongkan parameter ini, dan JANGAN '
                            .'memindahkan detail barangnya ke parameter category.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'WAJIB salah satu nama kategori PERSIS dari hasil get_finance_categories. '
                            .'Ini adalah pengelompokan umum (mis. "Consumption", "Food", "Transport"), BUKAN nama '
                            .'barangnya. CONTOH BENAR: user beli rokok -> description="Beli rokok", '
                            .'category="Consumption". CONTOH SALAH (JANGAN DITIRU): category="rokok" dengan '
                            .'description kosong -- "rokok" itu nama barang, bukan kategori. Kalau tidak ada '
                            .'kategori yang benar-benar pas, pilih yang paling umum/mendekati dari daftar; '
                            .'JANGAN mengarang nama kategori baru.',
                    ],
                    'account_name' => [
                        'type' => 'string',
                        'description' => 'Nama akun/dompet sumber atau tujuan uang (mis. "BCA", "Dana", "Cash"). '
                            .'Kosongkan kalau user tidak menyebut -- otomatis pakai akun pertama.',
                    ],
                    'contact_name' => [
                        'type' => 'string',
                        'description' => 'Nama orang yang terkait transaksi ini, kalau user menyebutkannya '
                            .'(mis. "bayar utang ke Irfan"). Kosongkan kalau tidak relevan.',
                    ],
                    'date' => [
                        'type' => 'string',
                        'description' => 'Tanggal transaksi format "YYYY-MM-DD". Kosongkan untuk hari ini.',
                    ],
                ],
                'required' => ['type', 'amount', 'description', 'category'],
            ],
        ];
    }

    /**
     * Modul: Finance -- READ daftar kategori resmi. Wajib dibaca AI sebelum
     * record_transaction supaya tidak mengarang kategori sendiri.
     */
    public static function getFinanceCategories(): array
    {
        return [
            'name' => 'get_finance_categories',
            'description' => 'Membaca DAFTAR RESMI kategori transaksi keuangan yang tersedia (dipisah income & '
                .'expense). WAJIB dipanggil sebelum record_transaction supaya kategori yang dipakai benar-benar '
                .'ada di sistem, bukan karangan sendiri.',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Finance -- CREATE akun/dompet baru.
     */
    public static function createAccount(): array
    {
        return [
            'name' => 'create_account',
            'description' => 'Membuat akun keuangan BARU (rekening bank, e-wallet, uang cash, atau akun trading). '
                .'Panggil get_balances dulu untuk memastikan akun dengan nama serupa belum ada.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Nama akun, mis. "BCA", "Dana", "Dompet Cash".',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['bank', 'ewallet', 'cash', 'trading'],
                        'description' => 'Jenis akun. Default "bank" kalau user tidak menyebut.',
                    ],
                    'balance' => [
                        'type' => 'number',
                        'description' => 'Saldo awal dalam Rupiah. Default 0.',
                    ],
                    'notes' => [
                        'type' => 'string',
                        'description' => 'Catatan opsional.',
                    ],
                ],
                'required' => ['name', 'type'],
            ],
        ];
    }

    /**
     * Modul: Finance -- READ kontak.
     */
    public static function getContacts(): array
    {
        return [
            'name' => 'get_contacts',
            'description' => 'Membaca daftar kontak keuangan (orang/keluarga/karyawan/vendor/pelanggan) milik user. '
                .'WAJIB dipanggil dulu sebelum create_contact untuk cek apakah orangnya sudah terdaftar.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Kata kunci nama/nomor HP (1-2 kata unik saja). Kosongkan untuk semua kontak.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Finance -- CREATE kontak.
     */
    public static function createContact(): array
    {
        return [
            'name' => 'create_contact',
            'description' => 'Menambah kontak keuangan BARU. Panggil get_contacts dulu supaya tidak dobel.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Nama lengkap kontak.'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['person', 'family', 'employee', 'vendor', 'customer', 'other'],
                        'description' => 'Jenis relasi. Default "person" kalau user tidak menyebut.',
                    ],
                    'phone' => ['type' => 'string', 'description' => 'Nomor HP opsional.'],
                    'notes' => ['type' => 'string', 'description' => 'Catatan opsional.'],
                ],
                'required' => ['name', 'type'],
            ],
        ];
    }

    /**
     * Modul: Finance -- READ budget + realisasi bulan berjalan.
     */
    public static function getBudgets(): array
    {
        return [
            'name' => 'get_budgets',
            'description' => 'Membaca semua budget bulanan per kategori BESERTA realisasi pengeluaran bulan '
                .'berjalan dan sisanya. Gunakan untuk menjawab "budget makan bulan ini sisa berapa" atau '
                .'"aku sudah over budget belum".',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Finance -- CREATE budget.
     */
    public static function createBudget(): array
    {
        return [
            'name' => 'create_budget',
            'description' => 'Membuat budget/anggaran bulanan BARU untuk satu kategori. Satu kategori hanya boleh '
                .'punya satu budget -- panggil get_budgets dulu untuk cek. Nama kategori sebaiknya diambil dari '
                .'get_finance_categories.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string', 'description' => 'Nama kategori yang mau dibatasi.'],
                    'monthly_limit' => [
                        'type' => 'number',
                        'description' => 'Batas pengeluaran per bulan dalam Rupiah (angka polos).',
                    ],
                    'notes' => ['type' => 'string', 'description' => 'Catatan opsional.'],
                ],
                'required' => ['category', 'monthly_limit'],
            ],
        ];
    }

    /**
     * Modul: Finance -- READ utang.
     */
    public static function getDebts(): array
    {
        return [
            'name' => 'get_debts',
            'description' => 'Membaca semua utang/cicilan user beserta sisa yang belum lunas. WAJIB dipanggil '
                .'sebelum record_debt_payment untuk memastikan krediturnya benar-benar ada.',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Finance -- CREATE utang.
     */
    public static function createDebt(): array
    {
        return [
            'name' => 'create_debt',
            'description' => 'Mencatat utang/cicilan BARU. Panggil get_debts dulu supaya tidak dobel.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'creditor' => [
                        'type' => 'string',
                        'description' => 'Nama pemberi pinjaman / lembaga cicilan.',
                    ],
                    'total_debt' => [
                        'type' => 'number',
                        'description' => 'Total utang dalam Rupiah (angka polos).',
                    ],
                    'remaining_debt' => [
                        'type' => 'number',
                        'description' => 'Sisa yang belum dibayar. Kosongkan kalau sama dengan total_debt.',
                    ],
                    'monthly_payment' => [
                        'type' => 'number',
                        'description' => 'Cicilan per bulan. Kosongkan kalau tidak ada.',
                    ],
                    'due_date' => [
                        'type' => 'string',
                        'description' => 'Tanggal jatuh tempo format "YYYY-MM-DD". Opsional.',
                    ],
                    'notes' => ['type' => 'string', 'description' => 'Catatan opsional.'],
                ],
                'required' => ['creditor', 'total_debt'],
            ],
        ];
    }

    /**
     * Modul: Finance -- bayar cicilan utang.
     */
    public static function recordDebtPayment(): array
    {
        return [
            'name' => 'record_debt_payment',
            'description' => 'Mencatat PEMBAYARAN cicilan untuk utang yang SUDAH ADA. Otomatis mengurangi sisa '
                .'utang, memotong saldo akun, dan mencatatnya sebagai transaksi pengeluaran. WAJIB panggil '
                .'get_debts dulu -- kalau krediturnya tidak ada di daftar, JANGAN membuat utang baru, tanya user.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'creditor' => [
                        'type' => 'string',
                        'description' => 'Nama kreditur (1-2 kata unik saja), didapat dari hasil get_debts.',
                    ],
                    'amount' => [
                        'type' => 'number',
                        'description' => 'Nominal yang dibayar dalam Rupiah (angka polos).',
                    ],
                    'account_name' => [
                        'type' => 'string',
                        'description' => 'Nama akun sumber dana. Kosongkan untuk pakai akun pertama.',
                    ],
                    'paid_at' => [
                        'type' => 'string',
                        'description' => 'Tanggal bayar format "YYYY-MM-DD". Kosongkan untuk hari ini.',
                    ],
                    'notes' => ['type' => 'string', 'description' => 'Catatan opsional.'],
                ],
                'required' => ['creditor', 'amount'],
            ],
        ];
    }

    /**
     * Modul: Finance -- READ analitik ringkas.
     */
    public static function getAnalytics(): array
    {
        return [
            'name' => 'get_analytics',
            'description' => 'Membaca ringkasan analitik keuangan: total pemasukan, pengeluaran, cashflow, net '
                .'worth, total kas, sisa utang, plus rincian per kategori dan tren bulanan. Gunakan untuk '
                .'pertanyaan seperti "gimana keuangan aku bulan ini" atau "pengeluaran terbesar aku apa".',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'year' => [
                        'type' => 'string',
                        'description' => 'Filter tahun, mis. "2026". Kosongkan untuk semua tahun.',
                    ],
                    'month' => [
                        'type' => 'string',
                        'description' => 'Filter bulan angka 1-12. Kosongkan untuk setahun penuh.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Habits -- READ.
     */
    public static function getHabits(): array
    {
        return [
            'name' => 'get_habits',
            'description' => 'Membaca daftar habit/kebiasaan aktif milik user, termasuk status sudah/belum '
                .'dikerjakan HARI INI. WAJIB dipanggil dulu sebelum create_habit (cek duplikat) dan berguna '
                .'sebelum log_habit untuk tahu nama habit yang benar.',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Habits -- CREATE.
     */
    public static function createHabit(): array
    {
        return [
            'name' => 'create_habit',
            'description' => 'Membuat habit/kebiasaan rutin BARU. Panggil get_habits dulu supaya tidak dobel.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Nama habit, mis. "Olahraga pagi".'],
                    'description' => ['type' => 'string', 'description' => 'Penjelasan opsional.'],
                    'emoji' => ['type' => 'string', 'description' => 'Satu emoji yang mewakili habit ini.'],
                    'frequency' => [
                        'type' => 'string',
                        'enum' => ['daily', 'weekly'],
                        'description' => 'Frekuensi. Default "daily".',
                    ],
                    'reminder_time' => [
                        'type' => 'string',
                        'description' => 'Jam pengingat format "HH:MM" (24 jam). Opsional.',
                    ],
                    'due_time' => [
                        'type' => 'string',
                        'description' => 'Batas jam pengerjaan format "HH:MM". Opsional.',
                    ],
                ],
                'required' => ['title'],
            ],
        ];
    }

    /**
     * Modul: Pomodoro -- mulai sesi fokus.
     */
    public static function startFocusSession(): array
    {
        return [
            'name' => 'start_focus_session',
            'description' => 'Menyalakan timer Pomodoro dan mulai menghitung waktu fokus SEKARANG. Panggil '
                .'kalau user bilang "aku mau fokus", "mulai fokus", "gas kerja", atau sejenisnya. Timer ini '
                .'sama dengan yang tampil di header aplikasi web, jadi user bisa melihat & menghentikannya '
                .'dari sana juga. Untuk mencatat kerja yang SUDAH LEWAT, jangan pakai ini -- pakai '
                .'log_focus_session.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['focus', 'short', 'long'],
                        'description' => 'Jenis sesi. Default "focus". Pakai "short"/"long" kalau user bilang '
                            .'mau istirahat sebentar / istirahat panjang.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Pomodoro -- hentikan sesi berjalan.
     */
    public static function stopFocusSession(): array
    {
        return [
            'name' => 'stop_focus_session',
            'description' => 'Menghentikan sesi Pomodoro yang sedang berjalan dan MENYIMPAN durasinya ke '
                .'database. Panggil kalau user bilang "fokus selesai", "udahan dulu", "stop", atau sejenisnya. '
                .'Setelah ini, sebutkan ke user berapa lama dia barusan fokus.',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Pomodoro -- catat sesi yang sudah lewat.
     */
    public static function logFocusSession(): array
    {
        return [
            'name' => 'log_focus_session',
            'description' => 'Mencatat waktu fokus yang SUDAH TERJADI dari rentang jam yang disebut user, '
                .'tanpa perlu timer berjalan. Panggil kalau user cerita mengerjakan sesuatu pada rentang jam '
                .'tertentu, misalnya "jam 09.00 sampai 11.20 aku memasang jaringan di Ruang Keuangan" -- itu '
                .'2 jam 20 menit waktu fokus yang layak masuk hitungan. Tanyakan konfirmasi ke user dulu '
                .'sebelum mencatat kalau dia tidak eksplisit minta dihitung sebagai waktu fokus.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'start_time' => [
                        'type' => 'string',
                        'description' => 'Waktu mulai format "YYYY-MM-DD HH:MM". Kalau user cuma menyebut jam '
                            .'tanpa tanggal, anggap hari ini.',
                    ],
                    'end_time' => [
                        'type' => 'string',
                        'description' => 'Waktu selesai format "YYYY-MM-DD HH:MM".',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['focus', 'short', 'long'],
                        'description' => 'Default "focus". Pakai "long" kalau yang diceritakan justru istirahat panjang.',
                    ],
                ],
                'required' => ['start_time', 'end_time'],
            ],
        ];
    }

    /**
     * Modul: Pomodoro -- baca statistik fokus.
     */
    public static function getFocusStats(): array
    {
        return [
            'name' => 'get_focus_stats',
            'description' => 'Membaca statistik fokus: total waktu fokus hari ini, total istirahat, jumlah '
                .'sesi, Score produktivitas 0-100, jumlah task masuk & selesai, plus rekap beberapa hari '
                .'terakhir. WAJIB dipanggil untuk menjawab pertanyaan seperti "berapa lama aku fokus hari '
                .'ini", "berapa score fokusku", atau "gimana produktivitasku minggu ini". Hasilnya juga '
                .'memberitahu apakah ada sesi yang sedang berjalan sekarang.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'days' => [
                        'type' => 'integer',
                        'description' => 'Berapa hari terakhir yang ikut dirangkum (1-30). Default 7.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Journal -- READ.
     */
    public static function getJournals(): array
    {
        return [
            'name' => 'get_journals',
            'description' => 'Membaca catatan jurnal/diary user (judul, cuplikan isi, mood, tanggal). Gunakan '
                .'untuk menjawab pertanyaan tentang apa yang pernah user tulis atau rasakan.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Kata kunci pencarian (1-2 kata unik saja). Kosongkan untuk yang terbaru.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * Modul: Journal -- CREATE.
     */
    public static function createJournal(): array
    {
        return [
            'name' => 'create_journal',
            'description' => 'Menulis entri jurnal/diary BARU. Panggil ini kalau user minta "catat di jurnal", '
                .'"tulis diary", atau menceritakan refleksi panjang yang layak disimpan.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Judul singkat entri jurnal.'],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Isi jurnal dalam teks biasa. Pisahkan paragraf dengan baris baru.',
                    ],
                    'mood' => [
                        'type' => 'string',
                        'enum' => ['great', 'good', 'neutral', 'low', 'bad'],
                        'description' => 'Suasana hati user. Default "neutral".',
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Daftar tag opsional, mis. ["kerjaan", "refleksi"].',
                    ],
                ],
                'required' => ['title', 'content'],
            ],
        ];
    }

    /**
     * Modul: Story -- CREATE (teks saja).
     */
    public static function createStory(): array
    {
        return [
            'name' => 'create_story',
            'description' => 'Memposting Story teks BARU ke feed pribadi user (semacam status Facebook). Panggil '
                .'kalau user bilang "post ke story", "update status", atau ingin mengabadikan momen singkat. '
                .'Untuk Story BERGAMBAR, user harus mengirim fotonya langsung ke bot -- tidak lewat tool ini.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'caption' => ['type' => 'string', 'description' => 'Teks Story yang mau diposting.'],
                ],
                'required' => ['caption'],
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
