<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subtask extends Model
{
    use HasFactory;

    // =========================
    // TABLE
    // =========================
    protected $table = 'subtasks';

    // =========================
    // MASS ASSIGNMENT
    // =========================
    protected $fillable = [
        'task_id',
        'title',
        'done',
        'completed_at',
        'position',
    ];

    // =========================
    // CASTS
    // =========================
    protected $casts = [
        'done' => 'boolean',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // =========================================================
    // 🌟 JEMBATAN AKSESOR
    // Agar Telegram dan kode lama tetap bisa menggunakan key
    // 'completed' meskipun field database adalah 'done'
    // =========================================================
    public function getCompletedAttribute(): bool
    {
        return (bool) $this->done;
    }

    public function setCompletedAttribute($value): void
    {
        $this->attributes['done'] = (bool) $value;
    }

    // =========================
    // RELATIONS
    // =========================
    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
