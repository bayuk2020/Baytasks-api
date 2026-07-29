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

        $schedule
            ->command('baytasks:habit-reminders')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/habit_reminders.log'));
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
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/daily.log'));
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
