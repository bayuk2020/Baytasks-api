<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Budget extends Model
{
    protected $table = 'finance_budgets';
    protected $fillable = [
        'id',
        'user_id',
        'category',
        'monthly_limit',
        'notes',
        'is_active',
    ];
    protected $casts = [
        'monthly_limit' => 'float',
        'is_active' => 'boolean',
    ];
    public $incrementing = false;
    protected $keyType = 'string';
}