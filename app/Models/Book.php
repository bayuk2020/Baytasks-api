<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
protected $fillable = [

    'title',
    'author',

    'cover_image',
    'cover_path',

    'format',
    'file_path',

    'total_pages',
    'current_page',

    'status',
];

    public function notes()
    {
        return $this->hasMany(
            BookNote::class
        );
    }

    public function readingSessions()
    {
        return $this->hasMany(
            ReadingSession::class
        );
    }
}