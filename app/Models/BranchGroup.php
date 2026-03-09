<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchGroup extends Model
{
    use HasFactory;

    public const CONTACT_VISIBILITY_GROUP_ONLY = 'group_only';
    public const CONTACT_VISIBILITY_BRANCH = 'branch';

    protected $fillable = [
        'branch_id',
        'name',
        'description',
        'contact_visibility_mode',
    ];

    public static function contactVisibilityModes(): array
    {
        return [
            self::CONTACT_VISIBILITY_GROUP_ONLY,
            self::CONTACT_VISIBILITY_BRANCH,
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
