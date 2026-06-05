<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalTag extends Model
{
    protected $fillable = [

        'journal_id',

        'tag',
    ];

    public function journal()
    {
        return $this->belongsTo(
            Journal::class
        );
    }
}