<?php

namespace App\Services\GroupAccess;

use App\Models\Client;
use App\Models\Property;
use App\Models\User;
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Builder;

/** Count visible related cards without removing an otherwise accessible booking. */
class BookingReportCounts
{
    public function __construct(private readonly RopGroupAccess $access) {}

    public function select(Builder $query, ?User $actor): void
    {
        if (! $actor?->hasRole('rop')) {
            $query->selectRaw('COUNT(DISTINCT bookings.property_id) as unique_properties')
                ->selectRaw('COUNT(DISTINCT COALESCE(bookings.crm_client_id, bookings.client_id)) as unique_clients');
            return;
        }

        $query->leftJoinSub($this->access->scope(Property::query(), $actor, 'properties.branch_group_id', 'properties.branch_id')
            ->select('properties.id'), 'report_visible_properties', 'report_visible_properties.id', '=', 'bookings.property_id');
        $query->leftJoinSub($this->access->scope(Client::query(), $actor, 'clients.branch_group_id', 'clients.branch_id')
            ->select('clients.id'), 'report_visible_clients', 'report_visible_clients.id', '=', 'bookings.crm_client_id');
        $query->leftJoinSub($this->access->employees($actor)->select('users.id'), 'report_visible_legacy_clients',
            'report_visible_legacy_clients.id', '=', 'bookings.client_id');

        $query->selectRaw('COUNT(DISTINCT report_visible_properties.id) as unique_properties')
            ->selectRaw("COUNT(DISTINCT CASE
                WHEN report_visible_clients.id IS NOT NULL THEN CONCAT('crm:', report_visible_clients.id)
                WHEN bookings.crm_client_id IS NULL AND report_visible_legacy_clients.id IS NOT NULL THEN CONCAT('user:', report_visible_legacy_clients.id)
                ELSE NULL END) as unique_clients");
    }
}
