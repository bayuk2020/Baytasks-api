<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Habit extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'emoji',
        'color',
        'frequency',
        'target',
        'xp_per_completion',
        'streak',
        'best_streak',
        'reminder_time',
        'archived',
    ];

    protected $casts = [
        'archived' => 'boolean',
    ];

    // =========================
    // LOGS
    // =========================

    public function logs()
    {
        return $this->hasMany(
            HabitLog::class
        );
    }
}