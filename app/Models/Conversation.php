<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';
    public const TYPE_GROUP = 'group';
    public const TYPE_SUPPORT = 'support';

    protected $fillable = [
        'type',
        'name',
        'direct_key',
        'created_by',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_DIRECT,
            self::TYPE_GROUP,
            self::TYPE_SUPPORT,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }

    public function supportThread(): HasOne
    {
        return $this->hasOne(SupportThread::class);
    }
}
