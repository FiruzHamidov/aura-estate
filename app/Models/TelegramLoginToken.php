<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLoginToken extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'token',
        'telegram_user_id',
        'telegram_username',
        'telegram_chat_id',
        'expires_at',
        'confirmed_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
