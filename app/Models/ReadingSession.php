<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReadingSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'book_id',
        'previous_page',
        'new_page',
        'pages_read',
        'created_at',
    ];

    public function book()
    {
        return $this->belongsTo(
            Book::class
        );
    }
}