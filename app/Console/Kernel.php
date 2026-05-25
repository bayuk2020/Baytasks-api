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

    protected function schedule(
        Schedule $schedule
    ): void {

        // =========================
        // TELEGRAM REMINDERS
        // =========================

        $schedule

            ->command(
                'baytasks:reminders'
            )

            ->everyMinute()

            ->withoutOverlapping()

            ->appendOutputTo(
                storage_path(
                    'logs/reminders.log'
                )
            );

        // =========================
        // RECURRING TASKS
        // =========================

        $schedule

            ->call(
                fn () =>
                app(
                    RecurringTaskService::class
                )->run()
            )

            ->name(
                'recurring-tasks'
            )

            ->everyMinute()

            ->withoutOverlapping()

            ->appendOutputTo(
                storage_path(
                    'logs/recurring.log'
                )
            );

            // =========================
// DAILY BRIEFING
// =========================

$schedule

    ->call(
        fn () =>
        app(
            DailyBriefing::class
        )->run()
    )

    ->name(
        'daily-briefing'
    )

    ->dailyAt(
        '10:00'
    )
    // ->everyMinute()

    ->withoutOverlapping()

    ->appendOutputTo(
        storage_path(
            'logs/daily.log'
        )
    );
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