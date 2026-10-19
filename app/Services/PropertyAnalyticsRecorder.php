<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PropertyAnalyticsRecorder
{
    public function record(int $propertyId, string $column, ?User $viewer = null): void
    {
        if (! in_array($column, ['views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks'], true)) {
            throw new \InvalidArgumentException('Unsupported property metric');
        }
        $increments = [$column => 1];
        $role = $column === 'views_count' ? $viewer?->role?->slug : null;
        if ($role && $role !== 'client') {
            $increments['non_client_views_count'] = 1;
        }
        $day = now('Asia/Dushanbe')->toDateString();
        DB::transaction(function () use ($propertyId, $increments, $day) {
            // Lock/update the property first to serialize concurrent daily-row creation.
            DB::table('properties')->where('id', $propertyId)->incrementEach($increments);
            DB::table('property_analytics_daily')->insertOrIgnore(['property_id' => $propertyId, 'day' => $day]);
            DB::table('property_analytics_daily')->where('property_id', $propertyId)->where('day', $day)->incrementEach($increments);
        });
    }
}
