<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $table = 'finance_contacts';

    protected $fillable = [
        'id',
        'name',
        'type',
        'phone',
        'notes',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
