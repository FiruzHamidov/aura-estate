<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChatSession extends Model
{
    protected $fillable = [
        'session_uuid','user_id','language','last_user_message_at','last_assistant_message_at','meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_user_message_at' => 'datetime',
        'last_assistant_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            if (empty($s->session_uuid)) {
                $s->session_uuid = (string) Str::uuid();
            }
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
