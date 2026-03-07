<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrmAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'actor_id',
        'event',
        'old_values',
        'new_values',
        'context',
        'message',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'context' => 'array',
    ];

    public function auditable()
    {
        return $this->morphTo();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
