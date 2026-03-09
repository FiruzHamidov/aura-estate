<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'lat',
        'lng',
        'landmark',
        'photo',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function branchGroups()
    {
        return $this->hasMany(BranchGroup::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function dealPipelines()
    {
        return $this->hasMany(DealPipeline::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }
}
