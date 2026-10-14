<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyRoomStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->integer('rooms')->nullable();
            $table->string('apartment_type')->nullable();
            $table->string('moderation_status')->default('approved');
            $table->timestamp('sold_at')->nullable();
            $table->string('offer_type')->default('sale');
            $table->unsignedBigInteger('repair_type_id')->default(1);
            $table->decimal('price')->default(100);
            $table->string('currency')->default('TJS');
        });
    }

    public function test_stats_count_all_room_buckets_without_including_hidden_properties(): void
    {
        foreach ([0, 1, 2, 2, 3, 4, 5, 6, 8, null] as $rooms) {
            DB::table('properties')->insert(['rooms' => $rooms]);
        }
        DB::table('properties')->insert(['apartment_type' => '2-комнатная']);
        DB::table('properties')->insert(['rooms' => 2, 'moderation_status' => 'pending']);
        DB::table('properties')->insert(['rooms' => 2, 'sold_at' => now()]);
        $this->getJson('/api/feed/properties/stats')->assertOk()->assertExactJson([
            'total' => 11,
            'room_counts' => ['studio' => 1, '1' => 1, '2' => 3, '3' => 1, '4' => 1, '5' => 1, '6+' => 2],
        ]);
        $this->getJson('/api/properties/count')->assertOk()->assertJsonPath('count', 11);
        $this->getJson('/api/feed/properties/stats?moderation_status=pending')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_stats_share_combined_filters_and_range_validation_with_count(): void
    {
        foreach ([
            ['rooms' => 2, 'repair_type_id' => 3],
            ['rooms' => 3, 'repair_type_id' => 4],
            ['rooms' => 4, 'repair_type_id' => 3],
            ['rooms' => 2, 'repair_type_id' => 3, 'offer_type' => 'rent'],
            ['rooms' => 2, 'repair_type_id' => 9],
        ] as $row) {
            DB::table('properties')->insert($row);
        }
        $query = '?offer_type=sale&roomsFrom=2&roomsTo=3&repair_type_id=3,4';
        $this->getJson('/api/feed/properties/stats'.$query)->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('room_counts.2', 1)
            ->assertJsonPath('room_counts.3', 1)->assertJsonPath('room_counts.4', 0);
        $this->getJson('/api/properties/count'.$query)->assertOk()->assertJsonPath('count', 2);
        $this->getJson('/api/feed/properties/stats?roomsFrom=3&roomsTo=2')->assertUnprocessable();
    }
}
