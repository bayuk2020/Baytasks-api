<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DebtPayment extends Model
{
    protected $table = 'finance_debt_payments';
    public $timestamps = false;
    protected $fillable = [
        'id',
        'debt_id',
        'account_id',
        'amount',
        'paid_at',
        'notes',
    ];
    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];
    public $incrementing = false;
    protected $keyType = 'string';
    public function debt()
    {
        return $this->belongsTo(Debt::class);
    }
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}