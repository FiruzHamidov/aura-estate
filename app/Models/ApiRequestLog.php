<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'trace_id',
        'user_id',
        'role_slug',
        'method',
        'path',
        'route_name',
        'controller_action',
        'status_code',
        'duration_ms',
        'ip_address',
        'user_agent',
        'client_locale',
        'request_query',
        'request_body',
        'error_code',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'request_query' => 'array',
        'request_body' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
