<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramSetting extends Model
{
    use HasFactory;

    protected $table =
        'telegram_settings';

    public $timestamps =
        false;

    protected $fillable = [
        'user_id',
        'chat_id',
        'enabled',
        'daily_briefing',
        'is_sleeping',
    ];

    protected $casts = [
        'enabled' =>
            'boolean',

        'daily_briefing' =>
            'boolean',

        'is_sleeping' =>
            'boolean',
    ];
}