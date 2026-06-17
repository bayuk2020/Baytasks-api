<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'finance_transactions';

    protected $fillable = [
        'id',
        'user_id',
        'account_id',
        'type',
        'category',
        'amount',
        'description',
        'transaction_date',
        'income_source_id',
        'contact_id',
        'transfer_group_id',
        'to_account_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'transaction_date' => 'date',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
