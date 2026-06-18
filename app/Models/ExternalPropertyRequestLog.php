<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalPropertyRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'external_property_request_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'comment',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(ExternalPropertyRequest::class, 'external_property_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
