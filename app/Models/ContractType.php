<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractType extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name'];

    public function properties()
    {
        return $this->hasMany(Property::class);
    }
}
