<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supaya sesi Pomodoro bisa dimulai dari mana saja (web, bot Telegram, atau
 * chat AI) dan diakhiri dari tempat yang berbeda, server perlu tahu ada sesi
 * yang SEDANG BERJALAN -- bukan cuma menerima segmen yang sudah jadi.
 *
 * Sesi terbuka = baris dengan `ended_at` NULL. Karena itu `ended_at` dan
 * `duration_seconds` harus boleh NULL (sebelumnya keduanya NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pomodoro_sessions', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->change();
            $table->unsignedInteger('duration_seconds')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sesi yang masih terbuka tidak punya padanan di skema lama --
        // ditutup dulu memakai waktu mulainya supaya tidak melanggar NOT NULL.
        Schema::table('pomodoro_sessions', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable(false)->change();
            $table->unsignedInteger('duration_seconds')->nullable(false)->change();
        });
    }
};
