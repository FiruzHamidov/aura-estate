<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalPropertyRequestPhoto extends Model
{
    use HasFactory;

    protected $hidden = ['file_path'];
    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return route('external-request-photo', ['photo' => $this->id]);
    }

    protected $fillable = [
        'external_property_request_id',
        'file_path',
        'position',
    ];

    public function request()
    {
        return $this->belongsTo(ExternalPropertyRequest::class, 'external_property_request_id');
    }
}
