<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

class BookingAttribution
{
    // Older schemas remain readable during a rolling deployment.
    public static function column(): string
    {
        return Schema::hasColumn('bookings', 'created_by') ? 'bookings.created_by' : 'bookings.agent_id';
    }
}
