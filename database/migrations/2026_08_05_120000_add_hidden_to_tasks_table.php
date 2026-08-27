<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `hidden` -- toggle manual untuk menyembunyikan task dari papan Kanban
 * setelah selesai, TANPA menghapus riwayatnya. Activity Log & Daily Log di
 * halaman Calendar sengaja TIDAK memfilter kolom ini -- riwayat aktivitas
 * tetap utuh terlepas dari task-nya disembunyikan atau tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'hidden')) {
                $table->boolean('hidden')->default(false)->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'hidden')) {
                $table->dropColumn('hidden');
            }
        });
    }
};
