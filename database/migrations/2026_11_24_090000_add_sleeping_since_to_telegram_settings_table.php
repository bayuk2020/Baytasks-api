<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom `sleeping_since` ke telegram_settings.
 *
 * Latar belakang: Sleep Mode hanya bisa dimatikan kalau AI benar-benar
 * memanggil tool `toggle_sleep_mode(status=false)` saat user menyapa pagi.
 * Pada 20 November 2026 AI menjawab sapaan "Selamat pagi" tanpa memanggil
 * tool itu, jadi `is_sleeping` tetap 1 sejak 19 November 23:04 dan SEMUA
 * notifikasi proaktif (morning brief, nightly summary, reminder task &
 * habit, daily briefing) dibungkam selama lima hari tanpa jejak apa pun.
 *
 * Kolom ini mencatat KAPAN Sleep Mode mulai menyala, supaya
 * TelegramSetting::sedangTidur() bisa membangunkan sendiri begitu waktunya
 * lewat -- tidak lagi bergantung pada AI yang harus ingat.
 *
 * Catatan: di proyek ini `migrations` cuma mencatat 3 dari 16 berkas, jadi
 * JANGAN jalankan `php artisan migrate` tanpa filter. Jalankan khusus
 * berkas ini saja:
 *   php artisan migrate --path=database/migrations/2026_11_24_090000_add_sleeping_since_to_telegram_settings_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('telegram_settings', 'sleeping_since')) {
            return;
        }

        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->timestamp('sleeping_since')
                ->nullable()
                ->after('is_sleeping');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('telegram_settings', 'sleeping_since')) {
            return;
        }

        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn('sleeping_since');
        });
    }
};
