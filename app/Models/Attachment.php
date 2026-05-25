<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasFactory;

    protected $table = 'attachments';

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'name',
        'size',
        'path',
    ];

    public function task()
    {
        return $this->belongsTo(
            Task::class
        );
    }
}