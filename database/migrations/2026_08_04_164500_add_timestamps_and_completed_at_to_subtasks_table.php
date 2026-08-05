<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `subtasks` cuma punya kolom id/task_id/title/done/position, padahal
 * App\Models\Subtask memakai timestamps Eloquent (default aktif) dan
 * SubtaskController mengisi `completed_at`. Akibatnya SEMUA jalur ini error 500:
 *   - POST   /api/subtasks             -> "Unknown column 'updated_at'"
 *   - PATCH  /api/subtasks/{id} done=1 -> "Unknown column 'completed_at'"
 *   - PATCH  /api/subtasks/{id} done=0 -> "Unknown column 'completed_at'"
 * TaskController juga sudah membaca `$sub->completed_at` untuk dikirim ke
 * frontend sebagai `completedAt`, jadi kolomnya memang diharapkan ada.
 *
 * Migrasi ini menambahkan tiga kolom yang hilang itu. Semuanya nullable supaya
 * 41 baris subtask yang sudah ada tidak bermasalah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            if (! Schema::hasColumn('subtasks', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('done');
            }

            if (! Schema::hasColumn('subtasks', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('subtasks', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['completed_at', 'created_at', 'updated_at'],
                fn (string $c) => Schema::hasColumn('subtasks', $c)
            ));

            if (! empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};
