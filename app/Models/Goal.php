<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    protected $table = 'goals';

    protected $fillable = [
        'user_id',
        'area_id',
        'title',
        'description',
        'target_amount',
        'current_amount',
        'due_date',
        'completed',
        'progress_percent'
    ];

    protected $casts = [
        'user_id' => 'integer',
        'area_id' => 'integer',
        'target_amount' => 'float',
        'current_amount' => 'float',
        'completed' => 'boolean',
        'progress_percent' => 'integer',
        'due_date' => 'date:Y-m-d',
    ];

    /**
     * Hubungan ke tabel induk life_areas
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(LifeArea::class, 'area_id', 'id');
    }

    /**
     * Hubungan ke anak tabel milestones
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'goal_id', 'id');
    }

    /**
     * Hubungan ke anak tabel quarterly_plans
     */
    public function quarterlyPlans(): HasMany
    {
        return $this->hasMany(QuarterlyPlan::class, 'goal_id', 'id');
    }

    /**
     * Hubungan ke anak tabel goal_reviews
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(GoalReview::class, 'goal_id', 'id');
    }

    /**
     * Hubungan ke anak tabel goal_links
     */
    public function links(): HasMany
    {
        return $this->hasMany(GoalLink::class, 'goal_id', 'id');
    }
}