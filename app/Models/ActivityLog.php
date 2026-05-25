<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table =
        'activity_logs';

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'user_id',
        'text',
        'created_at',
    ];

    protected $casts = [
        'created_at' =>
            'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(
            Task::class
        );
    }
}