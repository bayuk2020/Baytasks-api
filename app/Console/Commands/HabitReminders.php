<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Habit;
use App\Services\TelegramService;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * CATATAN: logika command ini SUDAH DIGABUNG ke SendTaskReminders.php
 * (baytasks:reminders) -- lihat "PENGKONDISIAN C" di sana. Command ini TIDAK
 * lagi didaftarkan di Kernel.php agar tidak jalan dobel/race dengan versi
 * gabungannya (file ini sebelumnya tidak punya cache-lock sama sekali, jadi
 * kalau tetap dijadwalkan bersamaan bisa kirim reminder yang sama berkali-kali
 * dalam window toleransi 60 detik). Dibiarkan tetap ada untuk dipanggil manual
 * (`php artisan baytasks:habit-reminders`) kalau suatu saat perlu debug terpisah.
 */
class HabitReminders extends Command
{
  protected $signature = 'baytasks:habit-reminders';
  protected $description = 'Send Telegram complex habits engine and snooze reminders only';

  public function handle()
  {
    Log::info('[baytasks:habit-reminders] START (manual run -- command ini tidak lagi dijadwalkan otomatis)');

    $telegram = new TelegramService();

    // =========================================================
    // AMBIL DATA CHAT ID UTAMA
    // =========================================================
    $setting = TelegramSetting::first();
    if (!$setting || !$setting->enabled || !$setting->chat_id) {
      Log::warning('[baytasks:habit-reminders] ABORT: telegram_settings tidak ada / enabled=false / chat_id kosong');
      dump('ERROR: NO TELEGRAM CHAT ID OR INTEGRATION DISABLED');
      return 1;
    }

    if ($setting->is_sleeping) {
      Log::info('[baytasks:habit-reminders] SKIP: is_sleeping=true');
      return 0;
    }

    $chatId = $setting->chat_id;

    // =========================================================
    // DEKLARASI WAKTU UTAMA (MENGGUNAKAN DETIK TIMESTAMP PHP)
    // =========================================================
    $now = time();
    $todayStr = Carbon::now('Asia/Jakarta')->toDateString();

    // NULL di kolom `archived` (bukan hanya `false`) harus tetap dianggap belum
    // diarsip -- lihat catatan panjang soal ini di SendTaskReminders.php.
    $notArchived = fn ($q) => $q->where('archived', false)->orWhereNull('archived');

    // 1. EVALUASI HABIT YANG TERLEWAT
    $expiredHabits = Habit::where($notArchived)
      ->whereNotNull('due_time')
      ->whereNotExists(function ($query) use ($todayStr) {
        $query->select(DB::raw(1))
          ->from('habit_logs')
          ->whereRaw('habit_logs.habit_id = habits.id')
          ->where('habit_logs.date', $todayStr);
      })->get();

    foreach ($expiredHabits as $exHabit) {
      $dueTimestamp = strtotime($todayStr . ' ' . $exHabit->due_time);
      $diff = $dueTimestamp - $now;

      // Toleransi tolerir 60 detik jika waktu melewati batas
      if ($diff <= 0 && $diff >= -60) {
        $totalSnoozeCount = DB::table('memories')
          ->where('type', 'habit_snooze_log')
          ->where('title', 'like', "%Habit ID: {$exHabit->id}%")
          ->whereDate('created_at', $todayStr)->count();

        DB::table('habit_logs')->insert([
          'habit_id'     => $exHabit->id,
          'date'         => $todayStr,
          'completed'    => false,
          'completed_at' => null,
          'notes'        => "Habit terlewat, user menunda habit selama {$totalSnoozeCount} kali.",
          'created_at'   => now(),
          'updated_at'   => now()
        ]);

        $exHabit->update(['snooze_until' => null, 'streak' => 0]);

        $telegram->sendMessage($chatId, "❌ <b>Habit Terlewat!</b>\nWaduh Bay, batas akhir pengerjaan <b>{$exHabit->emoji} {$exHabit->title}</b> sudah habis.");
      }
    }

    // 2. TRIGGER KIRIM NOTIFIKASI REMINDER UTAMA ATAU HASIL TUNDA
    $activeHabits = Habit::where($notArchived)
      ->whereNotExists(function ($query) use ($todayStr) {
        $query->select(DB::raw(1))
          ->from('habit_logs')
          ->whereRaw('habit_logs.habit_id = habits.id')
          ->where('habit_logs.date', $todayStr);
      })->get();

    foreach ($activeHabits as $habit) {
      $send = false;
      $isSnoozed = false;

      // Cek reminder_time (Toleransi rentang 60 detik seperti file task)
      if ($habit->reminder_time) {
        $reminderTimestamp = strtotime($todayStr . ' ' . $habit->reminder_time);
        $diffReminder = $reminderTimestamp - $now;
        if ($diffReminder <= 60 && $diffReminder >= -60) {
          $send = true;
        }
      }

      // Cek snooze_until
      if ($habit->snooze_until) {
        $snoozeTimestamp = strtotime($todayStr . ' ' . $habit->snooze_until);
        $diffSnooze = $snoozeTimestamp - $now;
        if ($diffSnooze <= 60 && $diffSnooze >= -60) {
          $send = true;
          $isSnoozed = true;
        }
      }

      if (!$send) {
        continue;
      }

      // CETAK LOG BIAR KELUAR DI CMD LU KAWAN!
      dump("MATCH HABIT PROCESSOR TELEGRAM ACTIVED: " . $habit->title);

      $dueLimit = $habit->due_time ? Carbon::parse($habit->due_time) : Carbon::parse('23:59:59');

      if ($isSnoozed) {
        $diff = Carbon::now('Asia/Jakarta')->diff($dueLimit);
        $diffText = "{$diff->h} jam {$diff->i} menit";
        $habitMessage = "⚠️ <b>Sudah 5 menit bay, mau sampai kapan nundanya?</b>\n\nKurang <b>{$diffText} lagi</b> sampai batas waktu.\nYuk eksekusi: {$habit->emoji} <b>{$habit->title}</b>!";
      } else {
        $timeFormatted = Carbon::parse($habit->reminder_time)->format('H:i');
        $habitMessage = "🔔 <b>Hai Bay, jam sudah menunjukkan pukul {$timeFormatted}.</b>\n\nJangan lupa {$habit->emoji} <b>{$habit->title}</b> " . ($habit->description ? "\"{$habit->description}\"" : "");
      }

      $habitMarkup = [
        'inline_keyboard' => [
          [
            ['text' => '✅ Tandai Selesai', 'callback_data' => 'habit_done_direct_' . $habit->id],
            ['text' => '⏳ Tunda 5 Menit', 'callback_data' => 'habit_snooze_5m_' . $habit->id]
          ]
        ]
      ];

      $telegram->sendMessage($chatId, $habitMessage, [
        'reply_markup' => json_encode($habitMarkup)
      ]);
    }

    return 0;
  }
}
