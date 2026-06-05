<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookNote extends Model
{
    protected $fillable = [
        'book_id',
        'page_number',
        'chapter',
        'title',
        'content',
    ];

    public function book()
    {
        return $this->belongsTo(
            Book::class
        );
    }
}