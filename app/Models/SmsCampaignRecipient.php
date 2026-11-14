<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsCampaignRecipient extends Model
{
    public $timestamps = false;

    protected $casts = ['submitted_at' => 'datetime'];

    public const STATUSES = ['pending', 'sending', 'accepted', 'delivered', 'failed', 'unknown', 'skipped', 'cancelled'];

    public static function mask(string $phone): string
    {
        return '••• '.substr($phone, -4);
    }
}
