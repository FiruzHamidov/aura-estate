<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class PropertyAnalyticsRecorder
{
    public function record(int $propertyId, string $column): void
    {
        if (! in_array($column, ['views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks'], true)) {
            throw new \InvalidArgumentException('Unsupported property metric');
        }
        $day = now('Asia/Dushanbe')->toDateString();
        DB::transaction(function () use ($propertyId, $column, $day) {
            // Lock/update the property first to serialize concurrent daily-row creation.
            DB::table('properties')->where('id', $propertyId)->increment($column);
            DB::table('property_analytics_daily')->insertOrIgnore(['property_id' => $propertyId, 'day' => $day]);
            DB::table('property_analytics_daily')->where('property_id', $propertyId)->where('day', $day)->increment($column);
        });
    }
}
