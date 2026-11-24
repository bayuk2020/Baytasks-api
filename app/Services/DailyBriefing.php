<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Task;
use App\Models\TelegramSetting;
use App\Services\IzinNotifikasi;

class DailyBriefing
{
    public function run()
    {
        // Semua syarat kirim di satu tempat -- lihat Services/IzinNotifikasi.
        //
        // Pemilik saklar untuk briefing ini sekarang `notif.daily_briefing.enabled`
        // di app_settings, BUKAN lagi kolom `telegram_settings.daily_briefing`.
        // Kolom lama itu dibiarkan ikut diperbarui oleh AppSettingController
        // supaya tidak saling bertentangan, tapi tidak dibaca lagi di sini.
        //
        // Jam diam tidak dihormati: jam briefing dipilih user sendiri.
        $izin = IzinNotifikasi::periksa('notif.daily_briefing.enabled', false);

        if (! $izin['boleh']) {
            return;
        }

        $setting = $izin['setting'];

        $open =
            Task::whereNull(
                'completed_at'
            )->count();

        $today =
            Task::whereDate(
                'due_at',
                now()->toDateString()
            )
            ->whereNull(
                'completed_at'
            )
            ->count();

        $overdue =
            Task::where(
                'due_at',
                '<',
                now()
            )
            ->whereNull(
                'completed_at'
            )
            ->count();


// =========================
// STREAK
// =========================

$days =
    Task::whereNotNull(
        'completed_at'
    )

    ->selectRaw(
        'DATE(completed_at) as d'
    )

    ->groupBy('d')

    ->orderBy(
        'd',
        'desc'
    )

    ->pluck('d')

    ->toArray();

$streak = 0;

if (
    count($days) > 0
) {

    $streak = 1;

    for (
        $i = 0;
        $i < count($days) - 1;
        $i++
    ) {

        $current =
            \Carbon\Carbon::parse(
                $days[$i]
            );

        $next =
            \Carbon\Carbon::parse(
                $days[$i + 1]
            );

        if (

            $current
                ->copy()
                ->subDay()
                ->toDateString()

            ===

            $next
                ->toDateString()

        ) {

            $streak++;

        } else {

            break;
        }
    }
}


        $message =
            "☀️ BayTasks Morning Briefing\n\n" .

            "📂 Open tasks: " .
            $open .
            "\n" .

            "📅 Due today: " .
            $today .
            "\n" .

            "⚠️ Overdue: " .
            $overdue .
            "\n\n" .
            "\n\n🔥 Streak: " .
            $streak .
            " days" .
            "🔥💲 Stay productive 💵🤑🔥";

        $telegram =
            new TelegramService();

        $telegram->sendMessage(
            $setting->chat_id,
            $message
        );

        // Jejak waktu untuk panel status di halaman Setting.
        AppSetting::catatStatus('status.daily_briefing.last_sent_at');
    }
}