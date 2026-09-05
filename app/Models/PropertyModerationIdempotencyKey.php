<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PropertyModerationIdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'property_id',
        'idempotency_key',
        'request_fingerprint',
        'route',
        'status',
        'response_status',
        'response_body',
        'response_content_type',
    ];

    protected $casts = [
        'response_status' => 'integer',
    ];
}
