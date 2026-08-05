<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PomodoroSession extends Model
{
    protected $table = 'pomodoro_sessions';

    protected $fillable = [
        'user_id',
        'mode',
        'started_at',
        'ended_at',
        'duration_seconds',
        'completed',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'completed' => 'boolean',
    ];

    public const MODES = ['focus', 'short', 'long'];
}
