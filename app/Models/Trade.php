<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Trade extends Model
{
    protected $table = 'finance_trades';
    protected $fillable = [
        'id',
        'user_id',
        'account_id',
        'symbol',
        'side',
        'quantity',
        'entry_price',
        'exit_price',
        'fees',
        'status',
        'opened_at',
        'closed_at',
        'notes',
    ];
    protected $casts = [
        'quantity' => 'float',
        'entry_price' => 'float',
        'exit_price' => 'float',
        'fees' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
    public $incrementing = false;
    protected $keyType = 'string';
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}