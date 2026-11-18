<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom urutan manual untuk milestone, supaya tahapan bisa disusun
 * ulang lewat drag & drop di halaman detail Goal.
 *
 * Sebelum ini frontend sudah mencoba mengurutkan dengan `(a.order || 0)`
 * (lihat MilestoneTimeline.tsx), tapi kolomnya tidak pernah ada di database —
 * jadi semua baris bernilai 0 dan urutannya jatuh ke urutan id dari MySQL.
 *
 * CATATAN PENTING soal menjalankan migrasi di proyek ini:
 * tabel `migrations` di sini TIDAK sinkron — sebagian besar tabel dibuat di
 * luar Laravel, sehingga `php artisan migrate:status` menandai hampir semua
 * migrasi lama sebagai "Pending" padahal tabelnya sudah ada. JANGAN jalankan
 * `php artisan migrate` polos; itu akan mencoba membuat ulang tabel yang sudah
 * ada. Jalankan file ini saja:
 *
 *   php artisan migrate --path=database/migrations/2026_11_18_090000_add_sort_order_to_milestones_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('milestones', 'sort_order')) {
            return;
        }

        Schema::table('milestones', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('weight');
            $table->index(['goal_id', 'sort_order'], 'milestones_goal_id_sort_order_index');
        });

        // Isi nilai awal mengikuti urutan id per goal, supaya tampilan yang
        // sekarang sudah dilihat user tidak tiba-tiba teracak setelah migrasi.
        DB::statement('SET @goal := 0, @row := 0');
        DB::statement(<<<'SQL'
            UPDATE milestones m
            JOIN (
                SELECT id,
                       @row := IF(@goal = goal_id, @row + 1, 1) AS rownum,
                       @goal := goal_id AS grp
                FROM milestones
                ORDER BY goal_id, id
            ) x ON x.id = m.id
            SET m.sort_order = x.rownum
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('milestones', 'sort_order')) {
            return;
        }

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropIndex('milestones_goal_id_sort_order_index');
            $table->dropColumn('sort_order');
        });
    }
};
