<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookBookmark extends Model
{
    protected $table = 'book_bookmarks';

    protected $fillable = [
        'book_id',
        'page_number',
        'label',
        'color',
    ];

    protected $casts = [
        'book_id'     => 'integer',
        'page_number' => 'integer',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
