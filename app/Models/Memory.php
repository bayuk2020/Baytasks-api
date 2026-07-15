<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    use HasFactory;

    protected $table = 'memories';

    protected $fillable = [
        'type',
        'source',
        'title',
        'content',
        'tags',
        'occurred_at',
    ];

    protected $casts = [
        'tags' => 'json', // Otomatis casting array/json biar gampang dibaca AI
        'occurred_at' => 'datetime',
    ];
}