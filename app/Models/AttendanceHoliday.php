<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

final class AttendanceHoliday extends Model
{
    protected $fillable = ['holiday_date', 'name', 'kind', 'note', 'created_by', 'updated_by'];

    protected $casts = ['holiday_date' => DateOnly::class];
}
