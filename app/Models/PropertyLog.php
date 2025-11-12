<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class PropertyLog extends Model
{
    protected $fillable = [
        'property_id',
        'user_id',
        'action',
        'changes',
        'comment',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
