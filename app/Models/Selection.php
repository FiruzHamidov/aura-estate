<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Selection extends Model
{
    use \App\Models\Concerns\ProjectsRopRelations;

    protected $fillable = [
        'branch_group_id', 'created_by', 'deal_id', 'contact_id', 'title', 'property_ids', 'channel',
        'note', 'selection_hash', 'selection_url', 'sent_at', 'viewed_at',
        'expires_at', 'status', 'meta',
    ];

    protected $casts = [
        'property_ids' => 'array',
        'meta' => 'array',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
