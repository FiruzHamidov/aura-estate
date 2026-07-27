<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = ['slug', 'name'];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
