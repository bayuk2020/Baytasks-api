<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HabitLog extends Model
{
    protected $fillable = [
        'habit_id',
        'date',
        'completed',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    // =========================
    // HABIT
    // =========================

    public function habit()
    {
        return $this->belongsTo(
            Habit::class
        );
    }
}