<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MindMapNode extends Model
{
    protected $table = 'mind_map_nodes';

    protected $fillable = [
        'mind_map_id',
        'parent_id',
        'type',
        'title',
        'done',
        'task_id',
        'position',
        'collapsed',
    ];

    protected $casts = [
        'done' => 'boolean',
        'collapsed' => 'boolean',
        'position' => 'integer',
    ];

    public const TYPES = ['topic', 'task'];

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Task di papan Kanban yang ditautkan ke node ini (opsional). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function mindMap(): BelongsTo
    {
        return $this->belongsTo(MindMap::class);
    }

    /**
     * Status centang yang BERLAKU.
     *
     * Kalau node ditautkan ke task papan, kolom task itu yang menentukan --
     * bukan kolom `done` lokal -- supaya tidak ada dua sumber kebenaran yang
     * bisa berbeda. Node tanpa tautan memakai `done` miliknya sendiri.
     */
    public function isDone(): bool
    {
        if ($this->task_id && $this->relationLoaded('task') && $this->task) {
            return $this->task->column_key === 'done';
        }

        if ($this->task_id && ! $this->relationLoaded('task')) {
            return optional($this->task)->column_key === 'done';
        }

        return (bool) $this->done;
    }
}
