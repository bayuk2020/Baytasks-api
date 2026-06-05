<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [

        'name',
        'type',
        'balance',
        'icon',
        'color',
        'notes',
    ];

    protected $casts = [

        'balance' => 'float',
    ];

    public function transactions()
    {
        return $this->hasMany(
            Transaction::class
        );
    }

    public function trades()
    {
        return $this->hasMany(
            Trade::class
        );
    }

    public function debtPayments()
    {
        return $this->hasMany(
            DebtPayment::class
        );
    }
}