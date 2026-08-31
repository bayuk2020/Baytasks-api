<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Mind Map: satu map berisi pohon node tak terbatas kedalamannya.
 *
 * Dua jenis node:
 *   - topic : pengelompok, tidak bisa dicentang
 *   - task  : bisa dicentang, DAN opsional ditautkan ke satu baris `tasks`
 *             (papan Kanban) lewat kolom `task_id`.
 *
 * Kalau `task_id` NULL -> node berdiri sendiri, status centangnya disimpan di
 * kolom `done`. Kalau terisi -> status centang MENGIKUTI kolom task di papan
 * (lihat MindMapController), supaya tidak ada dua sumber kebenaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mind_maps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('mind_map_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mind_map_id')->constrained('mind_maps')->cascadeOnDelete();

            // Self-reference: menghapus node otomatis menghapus seluruh
            // cabang di bawahnya (ON DELETE CASCADE), jadi tidak perlu
            // rekursi manual saat delete.
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->enum('type', ['topic', 'task'])->default('task');
            $table->string('title');
            $table->boolean('done')->default(false);

            // Tautan opsional ke task di papan Kanban. Kalau task-nya dihapus
            // dari papan, node TIDAK ikut hilang -- cukup jadi node biasa lagi.
            $table->unsignedBigInteger('task_id')->nullable();

            $table->integer('position')->default(0);
            $table->boolean('collapsed')->default(false);
            $table->timestamps();

            $table->foreign('parent_id')
                ->references('id')->on('mind_map_nodes')
                ->cascadeOnDelete();

            $table->foreign('task_id')
                ->references('id')->on('tasks')
                ->nullOnDelete();

            $table->index(['mind_map_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mind_map_nodes');
        Schema::dropIfExists('mind_maps');
    }
};
