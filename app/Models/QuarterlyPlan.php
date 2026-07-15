<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuarterlyPlan extends Model
{
    protected $table = 'quarterly_plans';

    protected $fillable = [
        'goal_id',
        'quarter',
        'year',
        'target_amount',
        'current_amount',
        'completed'
    ];

    protected $casts = [
        'goal_id' => 'integer',
        'quarter' => 'integer',
        'year' => 'integer',
        'target_amount' => 'float',
        'current_amount' => 'float',
        'completed' => 'boolean',
    ];

    /**
     * Hubungan balik ke tabel induk goals
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id', 'id');
    }
}