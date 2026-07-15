<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Milestone extends Model
{
    protected $table = 'milestones';

    protected $fillable = [
        'goal_id',
        'name',
        'target_value',
        'current_value',
        'due_date',
        'weight',
        'completed'
    ];

    protected $casts = [
        'goal_id' => 'integer',
        'target_value' => 'float',
        'current_value' => 'float',
        'weight' => 'integer',
        'completed' => 'boolean',
        'due_date' => 'date:Y-m-d',
    ];

    /**
     * Hubungan balik ke tabel induk goals
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id', 'id');
    }
}