<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use App\Support\ClientPhone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillClientsCommand extends Command
{
    protected $signature = 'clients:backfill';
    protected $description = 'Create and link CRM clients from existing bookings and properties';

    public function handle(): int
    {
        DB::transaction(function () {
            $this->backfillFromUsers();
            $this->backfillPropertyOwners();
            $this->backfillPropertyBuyers();
            $this->backfillBookings();
        });

        $this->info('Clients backfill finished.');

        return self::SUCCESS;
    }

    private function backfillFromUsers(): void
    {
        $clientRoleId = DB::table('roles')->where('slug', 'client')->value('id');

        if (!$clientRoleId) {
            return;
        }

        User::query()
            ->where('role_id', $clientRoleId)
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $client = $this->findOrCreateClient(
                        $user->name,
                        $user->phone,
                        $user->email,
                        $user->branch_id,
                        $user->id,
                        $user->id
                    );

                    Booking::query()
                        ->where('client_id', $user->id)
                        ->whereNull('crm_client_id')
                        ->update([
                            'crm_client_id' => $client->id,
                            'client_name' => DB::raw("COALESCE(client_name, '" . str_replace("'", "''", $client->full_name) . "')"),
                            'client_phone' => DB::raw("COALESCE(client_phone, '" . str_replace("'", "''", (string) $client->phone) . "')"),
                        ]);
                }
            });
    }

    private function backfillPropertyOwners(): void
    {
        Property::query()
            ->whereNull('owner_client_id')
            ->where(function ($query) {
                $query->whereNotNull('owner_name')
                    ->orWhereNotNull('owner_phone');
            })
            ->orderBy('id')
            ->chunkById(200, function ($properties) {
                foreach ($properties as $property) {
                    $creator = $property->creator;
                    $client = $this->findOrCreateClient(
                        $property->owner_name,
                        $property->owner_phone,
                        null,
                        $creator?->branch_id,
                        $property->created_by,
                        $property->agent_id ?: $property->created_by
                    );

                    $property->update(['owner_client_id' => $client->id]);
                }
            });
    }

    private function backfillPropertyBuyers(): void
    {
        Property::query()
            ->whereNull('buyer_client_id')
            ->where(function ($query) {
                $query->whereNotNull('buyer_full_name')
                    ->orWhereNotNull('buyer_phone');
            })
            ->orderBy('id')
            ->chunkById(200, function ($properties) {
                foreach ($properties as $property) {
                    $creator = $property->creator;
                    $client = $this->findOrCreateClient(
                        $property->buyer_full_name,
                        $property->buyer_phone,
                        null,
                        $creator?->branch_id,
                        $property->created_by,
                        $property->agent_id ?: $property->created_by
                    );

                    $property->update(['buyer_client_id' => $client->id]);
                }
            });
    }

    private function backfillBookings(): void
    {
        Booking::query()
            ->whereNull('crm_client_id')
            ->where(function ($query) {
                $query->whereNotNull('client_name')
                    ->orWhereNotNull('client_phone')
                    ->orWhereNotNull('client_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($bookings) {
                foreach ($bookings as $booking) {
                    $agent = $booking->agent;
                    $client = $this->findOrCreateClient(
                        $booking->client_name,
                        $booking->client_phone,
                        null,
                        $agent?->branch_id,
                        $booking->agent_id,
                        $booking->agent_id
                    );

                    $booking->update(['crm_client_id' => $client->id]);
                }
            });
    }

    private function findOrCreateClient(
        ?string $fullName,
        ?string $phone,
        ?string $email,
        ?int $branchId,
        ?int $createdBy,
        ?int $responsibleAgentId
    ): Client {
        $normalizedPhone = ClientPhone::normalize($phone);

        $query = Client::query();

        if ($normalizedPhone) {
            $query->where('phone_normalized', $normalizedPhone);
        } elseif ($fullName) {
            $query->where('full_name', $fullName)
                ->where('branch_id', $branchId);
        }

        $existing = $query->first();

        if ($existing) {
            return $existing;
        }

        return Client::create([
            'full_name' => $fullName ?: ('Client #' . now()->timestamp . '-' . random_int(1000, 9999)),
            'phone' => $phone,
            'phone_normalized' => $normalizedPhone,
            'email' => $email,
            'branch_id' => $branchId,
            'created_by' => $createdBy,
            'responsible_agent_id' => $responsibleAgentId,
            'status' => 'active',
        ]);
    }
}
