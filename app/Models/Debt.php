<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Debt extends Model
{
    protected $table = 'finance_debts';
    protected $fillable = [
        'id',
        'user_id',
        'creditor',
        'total_debt',
        'remaining_debt',
        'monthly_payment',
        'due_date',
        'status',
        'notes',
    ];
    protected $casts = [
        'total_debt' => 'float',
        'remaining_debt' => 'float',
        'monthly_payment' => 'float',
        'due_date' => 'date',
    ];
    public $incrementing = false;
    protected $keyType = 'string';
    public function payments()
    {
        return $this->hasMany(DebtPayment::class);
    }
}