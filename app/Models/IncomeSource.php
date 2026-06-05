<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomeSource extends Model
{
    protected $fillable = [

        'name',
        'color',
    ];

    public function transactions()
    {
        return $this->hasMany(
            Transaction::class
        );
    }
}