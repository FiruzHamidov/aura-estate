<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsVerificationCode extends Model
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    protected $fillable = ['phone', 'purpose', 'code', 'expires_at'];

    public $timestamps = true;

    public $dates = ['expires_at'];
}
