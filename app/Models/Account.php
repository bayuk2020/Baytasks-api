<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Account extends Model
{
    use SoftDeletes;
    protected $table = 'finance_accounts';
    protected $fillable = [
        'id',
        'user_id',
        'name',
        'type',
        'balance',
        'opening_balance',
        'icon',
        'color',
        'notes',
        'is_active',
    ];
    protected $casts = [
        'balance' => 'float',
        'opening_balance' => 'float',
        'is_active' => 'boolean',
    ];
    public $incrementing = false;
    protected $keyType = 'string';
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
    public function trades()
    {
        return $this->hasMany(Trade::class);
    }
    public function debtPayments()
    {
        return $this->hasMany(DebtPayment::class);
    }
}