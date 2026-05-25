<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {

            $table->id();

            // =========================
            // RELATION
            // =========================

            $table->unsignedBigInteger('board_id')
                ->default(1);

            $table->unsignedBigInteger('user_id')
                ->default(1);

            // =========================
            // TASK
            // =========================

            $table->string('title');

            $table->text('description')
                ->nullable();

            $table->text('notes')
                ->nullable();

            // =========================
            // STATUS
            // =========================

            $table->enum('column_key', [
                'backlog',
                'todo',
                'in_progress',
                'review',
                'done',
            ])->default('todo');

            // =========================
            // PRIORITY
            // =========================

            $table->enum('priority', [
                'low',
                'med',
                'high',
                'urgent',
            ])->default('med');

            // =========================
            // TAGS
            // =========================

            $table->json('tags')
                ->nullable();

            // =========================
            // DATE
            // =========================

            $table->dateTime('due_at')
                ->nullable();

            $table->dateTime('completed_at')
                ->nullable();

            // =========================
            // REMINDER
            // =========================

            $table->enum('reminder', [
                '10m',
                '1h',
                '1d',
            ])->nullable();

            // =========================
            // RECURRING
            // =========================

            $table->enum('recurring', [
                'none',
                'daily',
                'weekly',
                'monthly',
            ])->default('none');

            // =========================
            // ORDER
            // =========================

            $table->integer('position')
                ->default(0);

            // =========================
            // REMINDED
            // =========================

            $table->boolean('reminded')
                ->default(false);

            // =========================
            // TIMESTAMP
            // =========================

            $table->timestamps();

            // =========================
            // INDEX
            // =========================

            $table->index('column_key');

            $table->index('priority');

            $table->index('due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};