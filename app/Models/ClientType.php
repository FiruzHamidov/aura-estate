<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientType extends Model
{
    use HasFactory;

    public const SLUG_INDIVIDUAL = 'individual';
    public const SLUG_BUSINESS_OWNER = 'business_owner';

    protected $fillable = [
        'name',
        'slug',
        'is_business',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_business' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
