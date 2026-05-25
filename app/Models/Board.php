<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Board extends Model
{
    protected $table =
        'boards';

    protected $fillable = [

        'project_id',

        'name',
        'emoji',

        'position',
    ];

    // =========================
    // PROJECT
    // =========================

    public function project()
    {
        return $this->belongsTo(
            Project::class
        );
    }

    // =========================
    // TASKS
    // =========================

    public function tasks()
    {
        return $this->hasMany(
            Task::class
        );
    }
}