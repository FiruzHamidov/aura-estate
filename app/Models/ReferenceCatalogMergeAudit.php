<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceCatalogMergeAudit extends Model
{
    protected $fillable = [
        'actor_user_id',
        'catalog',
        'source_id',
        'source_label',
        'replacement_id',
        'replacement_label',
        'reassigned_count',
        'usage',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'usage' => 'array',
        'reassigned_count' => 'integer',
    ];
}
