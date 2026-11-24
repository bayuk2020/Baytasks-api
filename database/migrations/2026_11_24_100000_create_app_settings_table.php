<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel penyimpan setting aplikasi, berbentuk key-value.
 *
 * Kenapa tabel baru, bukan menambah kolom ke `telegram_settings`:
 * nama tabel itu sudah tidak cocok (setting yang akan masuk ke halaman
 * Setting jauh melebihi urusan Telegram), dan setiap setting baru nanti
 * tidak perlu migration lagi. `telegram_settings` tetap jadi pemilik
 * chat_id, saklar induk Telegram, dan keadaan Sleep Mode.
 *
 * Kolom `type` dipakai supaya nilai yang keluar dari sini sudah berbentuk
 * benar (bool/int/time/string) tanpa pemanggil harus menebak-nebak.
 *
 * Catatan: di proyek ini `migrations` cuma mencatat sebagian berkas, jadi
 * JANGAN jalankan `php artisan migrate` tanpa filter. Jalankan khusus
 * berkas ini:
 *   php artisan migrate --path=database/migrations/2026_11_24_100000_create_app_settings_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_settings')) {
            return;
        }

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();

            // Contoh: notif.morning_brief.enabled, notif.pulse.interval_minutes
            $table->string('key', 120)->unique();

            // Selalu disimpan sebagai teks; `type` yang menentukan cara bacanya.
            $table->text('value')->nullable();

            $table->string('type', 16)->default('string');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
