<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestSupportSession extends Model
{
    protected $fillable = [
        'public_id',
        'token_hash',
        'last_seen_at',
        'expires_at',
        'meta',
    ];

    protected $hidden = [
        'token_hash',
        'meta',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta' => 'array',
    ];

    public function supportThreads(): HasMany
    {
        return $this->hasMany(SupportThread::class, 'guest_session_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'guest_session_id');
    }
}
