<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn ($query) => \App\Support\BranchVisibility::apply($query, 'branches.id'));
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        // Management endpoints must still be able to restore hidden branches.
        return parent::resolveRouteBindingQuery($query, $value, $field)->withoutGlobalScope('visible');
    }

    protected $casts = ['is_hidden' => 'boolean'];

    protected $fillable = [
        'name',
        'lat',
        'lng',
        'landmark',
        'photo',
        'is_hidden',
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
