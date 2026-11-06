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

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();
        if (array_key_exists('owner_price', $attributes['changes'] ?? [])) {
            $viewer = auth()->user() ?? auth('sanctum')->user();
            if (! $viewer instanceof User || ! $viewer->canAccessPropertyOwnerPrice()) {
                // The same history is also nested in CRM responses. Keep the
                // audit in storage without disclosing this field to clients.
                unset($attributes['changes']['owner_price']);
            }
        }

        return $attributes;
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
