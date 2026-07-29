# BayTasks — Architecture Overview

> Dokumen ini adalah context anchor untuk pengembangan fitur baru. Ditulis berdasarkan hasil analisis source code Backend (`baytasks-api`) dan Frontend (`baytasks-smart-productivity`). Bukan dokumentasi API lengkap — fokus ke gambaran makro: apa yang ada, di mana letaknya, dan bagaimana semuanya saling terhubung.

Aplikasi ini adalah **sistem produktivitas personal single-user** (satu pengguna: "Bayu"), mencakup Task/Kanban board, Habit tracker, Goals & life planning, Finance tracker, Journal, Library (reading vault), dan asisten Telegram Bot yang terintegrasi ke semua modul di atas.

---

## 1. Tech Stack & Environment

### Backend — `baytasks-api`
- **Framework**: Laravel 10.10, PHP ^8.1
- **Auth**: Laravel Sanctum 3.3 terpasang, tapi **hampir tidak dipakai** — hanya route `GET /user` yang di-guard `auth:sanctum`. Seluruh route bisnis (`/tasks`, `/habits`, `/finance/*`, dll) bersifat publik/tanpa autentikasi, konsisten dengan sifat aplikasi single-user.
- **Database**: MySQL (`DB_DATABASE=laravel` secara default).
- **HTTP client**: Guzzle (via Laravel `Http` facade) untuk health-check; komunikasi ke Telegram Bot API pakai cURL manual (lihat `TelegramService`), bukan lewat `Http::` facade.
- **Hosting/runtime**: dijalankan lokal via Laragon (`php artisan serve`, port 8000), diekspos ke internet lewat **Cloudflare Tunnel** (`api.kabyra.my.id`) dan/atau **ngrok** sebagai fallback. Ada script `.bat`/`.vbs` (`run_serve.bat`, `run_tunnel.bat`, `run_worker.bat`, `run_healthcheck.bat`, dst.) untuk menjalankan server, tunnel, worker, dan health-check sebagai proses background di Windows.
- **Scheduling ganda**: selain Laravel Scheduler bawaan (`app/Console/Kernel.php`, `everyMinute()`), ada juga loop `.bat` (`for /l %x in (1,0,2) do ...` tiap 60 detik) yang memanggil command artisan yang sama secara langsung. Kalau menambah command baru yang perlu jalan berkala, pastikan didaftarkan di **kedua tempat** kalau ingin konsisten dengan pola yang sudah ada, atau cukup salah satu — tapi sadari currently keduanya dipakai.

### Frontend — `baytasks-smart-productivity`
- **Framework**: TanStack Start (React 19.2) + TanStack Router v1 (file-based routing) + TanStack React Query v5 untuk server-state caching.
- **Build tool**: Vite 7 + `@tailwindcss/vite` (Tailwind CSS v4).
- **State management**: Zustand — satu store global (`src/lib/store.ts`) untuk Tasks/Habits/Journal/Books/Boards, plus store terpisah khusus per modul besar: `src/lib/finance/store.ts` dan `src/lib/goals/store.ts`.
- **UI kit**: Radix UI primitives dibungkus gaya shadcn/ui (`src/components/ui/*`), animasi pakai `framer-motion`, drag-and-drop Kanban pakai `@dnd-kit/*`, chart pakai `recharts`.
- **Deployment**: Cloudflare Workers, via `@cloudflare/vite-plugin` + `wrangler.jsonc` (entry SSR: `src/server.ts`).
- **Koneksi ke Backend**: base URL API di-hardcode ke `https://api.kabyra.my.id/api` di `src/lib/api.ts` (bukan dari env var — perhatikan `.env.production` menyimpan `VITE_API_BASE_URL` ke domain ngrok yang berbeda dan tampaknya tidak dipakai oleh kode saat ini).

---

## 2. Struktur Folder Kustom

### Backend (`app/`)
| Path | Isi |
|---|---|
| `Http/Controllers/Api/` | Satu controller per resource (TaskController, HabitController, BoardController, GoalController, dst), flat — kecuali domain **Finance** yang punya subfolder sendiri: `Api/Finance/*Controller.php`. |
| `Http/Requests/Finance/`, `Http/Resources/Finance/` | Hanya dipakai oleh modul Finance (Contact) — pola FormRequest + API Resource yang lebih "idiomatic Laravel". Modul lain (Task, Habit, Board, dst) **tidak** pakai pola ini; mereka format response manual di controller. |
| `Models/` | Flat, satu file per tabel. Ada 1 file anomali: `Models/a.php` — ini **bukan kode PHP**, melainkan catatan spesifikasi alur bot Telegram dalam Bahasa Indonesia (dokumen desain lama, tersimpan salah folder). |
| `Services/` | Logic yang tidak masuk controller: `TelegramService` (kirim/edit pesan Telegram via cURL), `DailyBriefing` (hitung ringkasan pagi), `RecurringTaskService` (regenerasi task recurring). |
| `Support/FinanceTransformer.php` | Static class untuk mem-format model Finance (Account, Transaction, Budget, dst) jadi array camelCase — versi "manual transformer" untuk modul yang belum pakai API Resource. |
| `Telegram/Core/TelegramSessionManager.php` | Baca/tulis/reset baris di tabel `telegram_sessions` (state machine percakapan bot per `chat_id`). Diakses lewat `DB::table`, bukan Eloquent model. |
| `Telegram/Handlers/` | Satu class per "domain" alur bot: `TaskHandler.php` (task/board/wizard/pulse-check), `HabitHandler.php` (habit & leisure/santai flow), `TradingHandler.php`. **Catatan**: `FinanceHandler.php` masih kosong, dan `TelegramHandler.php` berisi class `TaskHandler` lain dengan constructor berbeda yang tampaknya legacy/tidak dipakai oleh autoloader (file nyata yang dipakai adalah `Telegram/Handlers/TaskHandler.php`). |
| `Console/Commands/` | Job terjadwal: `SendTaskReminders` (`baytasks:reminders`), `HabitReminders` (`baytasks:habit-reminders`), `SendMorningBrief`, `SendNightlySummary`, `CheckSystemHealth` (`baytasks:healthcheck` — cek server lokal & tunnel, kirim alert Telegram kalau down). |
| `database/sql/` | SQL mentah (`finance_contacts.sql`) untuk tabel yang **tidak** dibuatkan migration Laravel — lihat bagian 3. |

### Frontend (`src/`)
| Path | Isi |
|---|---|
| `routes/` | File-based routing TanStack Router. Nested page pakai dot-notation, mis. `finance.accounts.tsx` adalah child dari layout `finance.tsx`. `routeTree.gen.ts` **auto-generated**, jangan diedit manual. |
| `components/` | Komponen shared di root (`AppShell`, `Sidebar`, `Topbar`, `KanbanBoard`, `TaskCard`, `TaskModal`, `NotificationCenter`, `CommandPalette`), dan subfolder per domain: `finance/`, `goals/`, `habits/`, `library/`. |
| `components/ui/` | Primitive UI gaya shadcn/Radix, satu file per komponen (button, dialog, sheet, dst). |
| `lib/api.ts` | Fetch wrapper generik (`request()`) + object namespace per resource (`taskApi`, `boardApi`, `subtaskApi`, `telegramApi`, dst) untuk modul inti (task/board/habit/journal/book). |
| `lib/finance/`, `lib/goals/` | Masing-masing punya `api.ts` + `store.ts` sendiri (terpisah dari `lib/store.ts` utama), plus helper tambahan (`selectors.ts`, `analyticsStore.ts` untuk finance; `progress-engine.service.ts`, `goals.ts` untuk goals). |
| `lib/store.ts` | Zustand store utama — state Task, Habit, Journal, Book, Board, Notification, plus tipe-tipe domain Goals (terintegrasi dari tool eksternal "Lovable", lihat catatan di bagian 5). |

---

## 3. Database Schema & Relasi (Inti)

Ada **dua jalur pembuatan skema** yang berjalan berdampingan:

1. **Via Laravel migration** (`database/migrations/`) — hanya menaungi: `users`, `password_reset_tokens`, `failed_jobs`, `personal_access_tokens`, `tasks`, `habits`, `habit_logs`.
2. **Dibuat langsung di database** (tidak ada file migration-nya di repo) — mencakup mayoritas tabel aplikasi: `boards`, `subtasks`, `attachments`, `activity_logs`, `memories`, `telegram_settings`, `telegram_sessions`, `journals`, `journal_tags`, `books`, `book_notes`, `reading_sessions`, `life_areas`, `goals`, `milestones`, `quarterly_plans`, `goal_reviews`, `goal_links`, dan semua tabel `finance_*`. Contoh nyata: `database/sql/finance_contacts.sql` berisi `CREATE TABLE` mentah untuk `finance_contacts` dengan primary key `CHAR(36)` (UUID).

   **Implikasi penting**: source of truth skema untuk tabel-tabel ini ada di **database langsung**, bukan di repo. Kalau mau menambah kolom/tabel baru untuk fitur yang mengikuti pola ini, jangan berharap menemukan migration-nya — perlu cek struktur tabel aktual di database atau bikinkan migration baru (disarankan) sekalian dokumentasikan.

**Dua strategi Primary Key**:
- Domain "produktivitas inti" (Task, Board, Habit, Goal, Journal, Book, dst) → auto-increment integer.
- Seluruh domain **Finance** (Account, Transaction, Budget, Debt, DebtPayment, IncomeSource, Trade, Contact) → **UUID string** (`$incrementing = false; $keyType = 'string'`).

### Relasi utama
- **Board** 1—N **Task** (`board_id`)
- **Task** 1—N **Subtask**, 1—N **Attachment**, 1—N **ActivityLog**; Task N—1 **Milestone** (opsional, task bisa dikaitkan ke milestone goal)
- **Habit** 1—N **HabitLog**
- **Goal** (life goal, punya N—1 ke **LifeArea**) 1—N **Milestone**, 1—N **QuarterlyPlan**, 1—N **GoalReview**, 1—N **GoalLink**
  - **GoalLink** adalah relasi **polymorphic** (`linkable_type` + `linkable_id`) — satu goal bisa terhubung ke Task, Habit, Book, dsb secara dinamis lewat `morphTo()`.
- **Book** 1—N **BookNote**, 1—N **ReadingSession**
- **Journal** 1—N **JournalTag**
- **Finance**: **Account** 1—N Transaction/Trade/DebtPayment; **Contact** 1—N Transaction; **IncomeSource** 1—N Transaction; **Debt** 1—N DebtPayment.
- **Memory** — tabel lepas (tidak punya FK ke tabel lain), dipakai bot Telegram untuk menyimpan ringkasan aktivitas/percakapan bebas (`type`, `source`, `content`, `tags` json).
- **TelegramSetting** — 1 baris konfigurasi per user (`chat_id`, `enabled`, `daily_briefing`).
- **telegram_sessions** (raw table, tanpa Eloquent model) — state machine percakapan bot: `chat_id`, `step`, `active_task_id`, `form_state` (json), `context_data` (json).

---

## 4. Alur Komunikasi (Workflow)

### A. Frontend → Backend (REST API biasa)
1. Komponen/halaman React memanggil fungsi dari `lib/api.ts` (atau `lib/finance/api.ts` / `lib/goals/api.ts`), yang melakukan `fetch()` polos ke base URL production (`https://api.kabyra.my.id/api`) — bukan lewat React Query mutation langsung ke server, tapi biasanya dibungkus di Zustand store actions yang dipanggil komponen, dengan React Query dipakai untuk caching di beberapa tempat.
2. Backend menerima request di `routes/api.php` (satu file, semua route ada di sini, dikelompokkan pakai komentar `// ===` per domain), lalu masuk ke Controller terkait.
3. Controller mengambil data lewat Eloquent, lalu **membentuk response JSON camelCase secara manual** — baik lewat method privat seperti `formatTask()` (di TaskController), atau lewat `App\Support\FinanceTransformer::account()`/`transaction()`/dst (di controller Finance yang belum pakai Resource). Timestamp dikirim sebagai **epoch milliseconds** (`strtotime(...) * 1000`), bukan string ISO.
4. CORS (`config/cors.php`) hanya mengizinkan origin `https://baytasks.kabyra.my.id`.

### B. Telegram Webhook → Handler → Database
1. Telegram Bot API mem-`POST` update ke `POST /telegram/webhook` → `TelegramWebhookController::handle()`.
2. Controller ini **mengintersep dulu** dua callback spesifik dari tombol reminder (`task_done_{id}`, `task_notdone_{id}`) — langsung update model `Task` di sini, **tanpa lewat Handler atau TaskController**. Ini adalah jalur tulis ke database yang independen dari REST API biasa.
3. Untuk update lain (pesan teks atau callback tombol menu), controller:
   - Membaca/membuat baris sesi di `telegram_sessions` (lewat `DB::table`, key = `chat_id`) untuk tahu `step` percakapan saat ini.
   - Menentukan Handler mana yang berhak menangani, berdasarkan **pencocokan prefix string** pada `step` atau `callbackData` (mis. prefix `wiz_`, `manual_board_`, `select_task_` → `TaskHandler`; prefix `habit_`, `leisure_` → `HabitHandler`).
   - Instansiasi Handler dengan `(chatId, text, callbackData, messageId)`, lalu panggil `->execute()`.
4. Di dalam Handler: baca/tulis sesi lewat `TelegramSessionManager` (form wizard multi-step disimpan sebagai JSON di kolom `form_state`/`context_data`), lakukan operasi Eloquent (create/update Task, Subtask, Habit, dll), lalu balas ke user lewat `TelegramService::sendMessage()`/`editMessageText()` (cURL langsung ke Telegram Bot API, `parse_mode: HTML`, inline keyboard dikirim sebagai `reply_markup` JSON).

### C. Proses terjadwal (proaktif, bukan dipicu request)
- `app/Console/Kernel.php` mendaftarkan schedule (`->everyMinute()->withoutOverlapping()`) untuk command reminders/habit-reminders, plus pemanggilan langsung `RecurringTaskService` dan `DailyBriefing`.
- Command-command yang sama **juga** dipicu lewat script `.bat` yang loop tiap 60 detik di mesin lokal developer — jadi dua mekanisme scheduling berjalan paralel, bukan saling menggantikan.
- Semua notifikasi proaktif ini keluar lewat `TelegramService` ke `chat_id` yang tersimpan di `telegram_settings`.

---

## 5. Coding Conventions

- **Bahasa campuran by design**: identifier/struktur kode bahasa Inggris, tapi komentar, pesan bot, dan copy UI mayoritas **Bahasa Indonesia informal** (menyapa user sebagai "Bay"/"Bayu"). Ini bukan kebetulan — aplikasi ini personal, bukan produk multi-tenant, jadi nada bicara di kode/bot boleh tetap kasual.
- **Section banner comments**: blok `// =========================` dipakai untuk membagi region di dalam satu class/file (terlihat di Model, Controller, bahkan `api.ts` frontend) alih-alih memecah jadi banyak file kecil. Ikuti pola ini saat menambah method baru ke file yang sudah pakai gaya ini.
- **Dua pola response API berdampingan**: mayoritas controller memformat response manual (private `formatX()` method atau `FinanceTransformer` static method) ke camelCase; hanya slice **Finance/Contact** yang sudah pakai FormRequest (`app/Http/Requests/Finance/`) + API Resource (`app/Http/Resources/Finance/ContactResource.php`). Kalau menambah endpoint baru, pola Resource+FormRequest lebih idiomatic Laravel, tapi kalau memperluas endpoint lama, tetap konsisten dengan pola manual yang sudah dipakai di file itu.
- **Dua strategi Primary Key** (lihat bagian 3): tabel Finance = UUID string, tabel lain = auto-increment int. ID selalu di-cast `(string)` sebelum dikirim ke frontend, apa pun tipe penyimpanannya di database.
- **Timestamp ke frontend** = epoch milliseconds integer (`strtotime($x) * 1000`), bukan ISO 8601. Frontend membaca angka ini langsung sebagai `number`.
- **Bot Telegram = satu Handler class per domain**, dikonstruksi ulang setiap request `(chatId, text, callbackData, messageId)`, di-drive oleh string `step` yang disimpan di `telegram_sessions` dan dicocokkan pakai `str_starts_with`/`Str::is` — bukan enum atau routing table formal. Menambah alur bot baru = menambah prefix baru di array pengecekan `TelegramWebhookController` + branch baru di Handler terkait.
- **Kode/file yang sudah tidak relevan** — jangan dijadikan contoh pola saat menambah fitur: `Telegram/Handlers/FinanceHandler.php` masih kosong; `Telegram/Handlers/TelegramHandler.php` berisi class `TaskHandler` versi lain (constructor beda) yang tampak legacy; `Models/a.php` adalah catatan desain, bukan kode.
- **Single-user hardcoded**: `user_id` di-default ke `1` di beberapa tempat (create Task, ActivityLog). Tidak ada konsep multi-tenant/otorisasi per user di backend manapun.
- **Frontend state**: Zustand untuk client state per domain (store utama + store terpisah untuk `finance` dan `goals`), TanStack Query untuk server-state caching. Komponen UI generik di `components/ui/` (gaya shadcn), komponen fitur di `components/<domain>/`.
- **Modul Goals berasal dari integrasi eksternal** ("Lovable" AI app-builder) — tipe data di `lib/store.ts` menyimpan field ganda/fallback (mis. `Goal.name` sebagai fallback dari `title`, `Milestone.title` sebagai fallback dari `name`) dengan komentar eksplisit `// Fallback Lovable UI`. Kalau memperluas modul Goals, pertahankan pola dual-field ini agar kedua "sisi" schema tetap kompatibel, jangan hapus salah satu field tanpa cek pemakaiannya di kedua sisi.
