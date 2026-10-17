<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDeviceUserProfile extends Model
{
    protected $fillable = ['device_id', 'device_user_id', 'name'];
}
