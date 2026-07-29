<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    protected $table = 'finance_categories';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'type',
        'color',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public $incrementing = false;

    protected $keyType = 'string';
}
