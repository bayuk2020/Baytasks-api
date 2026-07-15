<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    // =========================
    // TABLE
    // =========================
    protected $table = 'tasks';

    // =========================
    // MASS ASSIGNMENT
    // =========================
    protected $fillable = [
        'board_id',
        'user_id',
        'title',
        'description',
        'notes',
        'column_key',
        'priority',
        'tags',
        'due_at',
        'reminder',
        'recurring',
        'position',
        'completed_at',
        'reminded',
    ];

    // =========================
    // CASTS
    // =========================
    protected $casts = [
        'tags' => 'array',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminded' => 'boolean',
    ];

    // =========================
    // DEFAULT VALUES
    // =========================
    protected $attributes = [
        'column_key' => 'todo',
        'priority' => 'med',
        'recurring' => 'none',
        'position' => 0,
        'reminded' => false,
    ];

    // =========================
    // RELATIONS
    // =========================
    public function subtasks()
    {
        return $this->hasMany(Subtask::class)->orderBy('position', 'asc');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function reminders()
    {
        return $this->hasMany(Reminder::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function milestone()
    {
        return $this->belongsTo(Milestone::class);
    }
}