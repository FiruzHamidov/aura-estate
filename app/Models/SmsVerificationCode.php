<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsVerificationCode extends Model
{
    protected $fillable = ['phone', 'code', 'expires_at'];

    public $timestamps = true;

    public $dates = ['expires_at'];
}
