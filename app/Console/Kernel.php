<?php

namespace App\Console;

use App\Services\RecurringTaskService;
use App\Services\DailyBriefing;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */

    protected function schedule(Schedule $schedule): void
    {
        /**
         * Menjalankan command untuk mengirim reminder tugas dan pulse check.
         * Command ini akan dieksekusi setiap menit oleh scheduler.
         * Logika di dalam command (SendTaskReminders.php) akan menentukan
         * apakah pulse check (tiap 15 menit) atau reminder tugas perlu dikirim.
         */
        $schedule
            ->command('baytasks:reminders')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/reminders.log'));

        // CATATAN: `baytasks:habit-reminders` SENGAJA tidak lagi dijadwalkan di sini.
        // Logikanya sudah digabung ke dalam `baytasks:reminders` (SendTaskReminders.php,
        // "PENGKONDISIAN C"). Command HabitReminders.php sebelumnya TIDAK punya
        // cache-lock sama sekali, jadi menjadwalkan keduanya bersamaan setiap menit
        // berisiko mengirim reminder habit yang sama berkali-kali dalam window
        // toleransi 60 detik. File & signature-nya masih ada untuk dipanggil manual
        // (`php artisan baytasks:habit-reminders`) kalau perlu debug terpisah.
        /**
         * Menjalankan service untuk membuat ulang tugas-tugas yang berulang (recurring).
         * Misalnya, tugas harian atau mingguan.
         */
        $schedule
            ->call(function () {
                try {
                    app(RecurringTaskService::class)->run();
                } catch (\Throwable $e) {
                    // Mencatat error yang lebih detail ke log utama Laravel
                    \Illuminate\Support\Facades\Log::channel('stack')->error(
                        'RecurringTaskService failed: ' . $e->getMessage(),
                        [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]
                    );
                    // Tetap lemparkan exception agar scheduler menandainya sebagai FAIL
                    throw $e;
                }
            })->name('recurring-tasks')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/recurring.log'));

        /**
         * Menjalankan service untuk mengirim rangkuman harian (daily briefing).
         * Dieksekusi setiap hari pada jam 10:00 pagi.
         */
        $schedule
            ->call(fn() => app(DailyBriefing::class)->run())
            ->name('daily-briefing')
            ->dailyAt('10:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/daily.log'));

        /**
         * Morning Quest Log (habit + task hari ini) -- setiap hari jam 08:00 WIB.
         */
        $schedule
            ->command('baytasks:morning-brief')
            ->dailyAt('08:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/morning_brief.log'));

        /**
         * Nightly Summary (rekap habit/task/subtask selesai hari ini) -- setiap
         * hari jam 21:00 WIB. NOTE: signature command-nya `baytasks:nightly-summary`
         * (SendNightlySummary.php) -- bukan `baytasks:nightly-brief`, yang tidak ada.
         */
        $schedule
            ->command('baytasks:nightly-summary')
            ->dailyAt('21:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/nightly_summary.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(
            __DIR__ .
                '/Commands'
        );

        require base_path(
            'routes/console.php'
        );
    }
}
