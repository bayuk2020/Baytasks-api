<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    protected $fillable = [

        'title',

        'content',

        'mood',
    ];

    public function tags()
    {
        return $this->hasMany(
            JournalTag::class
        );
    }
}