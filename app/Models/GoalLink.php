<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GoalLink extends Model
{
    protected $table = 'goal_links';

    protected $fillable = [
        'goal_id',
        'linkable_type',
        'linkable_id'
    ];

    protected $casts = [
        'goal_id' => 'integer',
        'linkable_id' => 'integer',
    ];

    /**
     * Hubungan balik ke tabel induk goals
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id', 'id');
    }

    /**
     * Relasi Polymorphic otomatis Laravel (bisa membaca Task, Habit, Book secara dinamis)
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}