<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = ['slug', 'name'];

    public function propertyTypes(): BelongsToMany
    {
        return $this->belongsToMany(PropertyType::class, 'document_type_property_type');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
