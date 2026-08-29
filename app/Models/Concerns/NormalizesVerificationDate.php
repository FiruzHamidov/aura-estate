<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

trait NormalizesVerificationDate
{
    public function setDataVerifiedAtAttribute($value): void
    {
        // SQL datetime columns have no offset. Store the application's wall clock,
        // matching Eloquent's interpretation when it reads the value back.
        $this->attributes['data_verified_at'] = $value === null || $value === ''
            ? null
            : Carbon::parse($value, config('app.timezone'))
                ->setTimezone(config('app.timezone'))
                ->format($this->getDateFormat());
    }
}
