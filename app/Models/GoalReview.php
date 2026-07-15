<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalReview extends Model
{
    protected $table = 'goal_reviews';

    protected $fillable = [
        'goal_id',
        'review_type',
        'status_evaluation',
        'blockers',
        'action_items'
    ];

    protected $casts = [
        'goal_id' => 'integer',
    ];

    /**
     * Hubungan balik ke tabel induk goals
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id', 'id');
    }
}