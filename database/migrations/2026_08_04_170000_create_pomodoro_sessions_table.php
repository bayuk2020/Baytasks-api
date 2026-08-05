<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekaman tiap SEGMEN sesi Pomodoro.
 *
 * Satu baris = satu potongan waktu dalam satu mode, dari user menekan Start
 * sampai segmen itu ditutup (pause, ganti mode, reset, atau timer habis).
 * Jadi urutan seperti ini menghasilkan 4 baris:
 *   Focus 10m20s -> Short Break 5m02s -> Focus 21m02s -> Long Break 10m30s
 *
 * `duration_seconds` diisi dari selisih wall-clock (ended_at - started_at)
 * yang dihitung frontend, BUKAN dari akumulasi tick setInterval -- supaya
 * tetap akurat walau tab di-background (browser melambatkan timer di tab
 * tidak aktif) atau user pindah halaman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pomodoro_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1);

            // focus = kerja, short = istirahat pendek, long = istirahat panjang
            $table->enum('mode', ['focus', 'short', 'long'])->index();

            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('duration_seconds');

            // true = timer habis sampai 00:00 dengan sendirinya,
            // false = ditutup lebih awal (user pause/reset/ganti mode).
            $table->boolean('completed')->default(false);

            $table->timestamps();

            // Query utama selalu "segmen pada rentang tanggal tertentu".
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pomodoro_sessions');
    }
};
