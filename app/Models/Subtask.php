<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subtask extends Model
{
    use HasFactory;

    protected $table = 'subtasks';
public $timestamps = false;
    protected $fillable = [
        'task_id',
        'title',
        'done',
        'position',
    ];

    protected $casts = [
        'done' => 'boolean',
    ];

    public function task()
    {
        return $this->belongsTo(
            Task::class
        );
    }
}