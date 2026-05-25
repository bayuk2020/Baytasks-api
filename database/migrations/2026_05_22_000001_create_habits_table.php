<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');

            $table->string('emoji')
                ->default('🔥');

            $table->string('color')
                ->default('cyan');

            $table->enum('frequency', [
                'daily',
                'weekly',
            ])->default('daily');

            $table->integer('target')
                ->default(1);

            $table->boolean('archived')
                ->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};