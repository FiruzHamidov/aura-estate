<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_kind', 16)
                ->default(Client::CONTACT_KIND_BUYER)
                ->after('client_type_id')
                ->index();
        });

        DB::table('clients')->update([
            'contact_kind' => Client::CONTACT_KIND_BUYER,
        ]);

        $applySellerSources = function ($query): void {
            $query
                ->whereIn('id', function ($subQuery) {
                    $subQuery->select('owner_client_id')
                        ->from('properties')
                        ->whereNotNull('owner_client_id');
                })
                ->orWhereIn('id', function ($subQuery) {
                    $subQuery->select('client_needs.client_id')
                        ->from('client_needs')
                        ->join('client_need_types', 'client_need_types.id', '=', 'client_needs.type_id')
                        ->where('client_need_types.slug', 'sell');
                });
        };

        $applyBuyerSources = function ($query): void {
            $query
                ->whereIn('id', function ($subQuery) {
                    $subQuery->select('buyer_client_id')
                        ->from('properties')
                        ->whereNotNull('buyer_client_id');
                })
                ->orWhereIn('id', function ($subQuery) {
                    $subQuery->select('crm_client_id')
                        ->from('bookings')
                        ->whereNotNull('crm_client_id');
                })
                ->orWhereIn('id', function ($subQuery) {
                    $subQuery->select('client_needs.client_id')
                        ->from('client_needs')
                        ->join('client_need_types', 'client_need_types.id', '=', 'client_needs.type_id')
                        ->whereIn('client_need_types.slug', ['buy', 'rent', 'invest']);
                });
        };

        DB::table('clients')
            ->where(function ($query) use ($applySellerSources) {
                $applySellerSources($query);
            })
            ->update([
                'contact_kind' => Client::CONTACT_KIND_SELLER,
            ]);

        DB::table('clients')
            ->where('contact_kind', Client::CONTACT_KIND_SELLER)
            ->where(function ($query) use ($applyBuyerSources) {
                $applyBuyerSources($query);
            })
            ->update([
                'contact_kind' => Client::CONTACT_KIND_BOTH,
            ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['contact_kind']);
            $table->dropColumn('contact_kind');
        });
    }
};
