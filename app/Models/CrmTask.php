<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrmTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_type_id',
        'assignee_id',
        'creator_id',
        'title',
        'description',
        'status',
        'result_code',
        'related_entity_type',
        'related_entity_id',
        'due_at',
        'completed_at',
        'source',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function type()
    {
        return $this->belongsTo(CrmTaskType::class, 'task_type_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
