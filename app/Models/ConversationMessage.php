<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id',
        'author_id',
        'type',
        'body',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_TEXT,
            self::TYPE_SYSTEM,
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
