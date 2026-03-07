<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'phone',
        'phone_normalized',
        'email',
        'note',
        'branch_id',
        'created_by',
        'responsible_agent_id',
        'status',
        'bitrix_contact_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleAgent()
    {
        return $this->belongsTo(User::class, 'responsible_agent_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'crm_client_id');
    }

    public function ownerProperties()
    {
        return $this->hasMany(Property::class, 'owner_client_id');
    }

    public function buyerProperties()
    {
        return $this->hasMany(Property::class, 'buyer_client_id');
    }
}
