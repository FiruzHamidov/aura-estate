<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    use HasFactory;
    use \App\Models\Concerns\ProjectsRopRelations;

    protected $fillable = [
        'branch_group_id',
        'user_id',
        'role_slug',
        'report_date',
        'calls_count',
        'ad_count',
        'meetings_count',
        'shows_count',
        'new_clients_count',
        'new_properties_count',
        'deposits_count',
        'deals_count',
        'sales_count',
        'comment',
        'plans_for_tomorrow',
        'submitted_at',
        'is_finalized',
        'finalized_at',
        'finalized_by',
        'updated_by',
        'updated_by_role',
        'updated_reason',
        'edit_source',
    ];

    protected $casts = [
        'report_date' => 'date',
        'calls_count' => 'integer',
        'ad_count' => 'integer',
        'meetings_count' => 'integer',
        'shows_count' => 'integer',
        'new_clients_count' => 'integer',
        'new_properties_count' => 'integer',
        'deposits_count' => 'integer',
        'deals_count' => 'integer',
        'sales_count' => 'decimal:4',
        'submitted_at' => 'datetime',
        'is_finalized' => 'boolean',
        'finalized_at' => 'datetime',
    ];

    public function branchGroup()
    {
        return $this->belongsTo(BranchGroup::class);
    }

    public function historicalRole()
    {
        return $this->belongsTo(Role::class, 'role_slug', 'slug');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
