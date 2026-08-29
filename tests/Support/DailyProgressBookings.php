<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DailyProgressBookings
{
    public static function seed(User $agent, string $date): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Daily progress fixtures require isolated SQLite :memory:.');
        }
        if (Schema::hasTable('properties') || Schema::hasTable('bookings')) {
            throw new \LogicException('Daily progress booking tables must not already exist.');
        }
        Schema::create('properties', fn (Blueprint $table) => $table->id());
        DB::table('properties')->insert(['id' => 1]);
        require_once database_path('migrations/2025_08_01_153110_create_bookings_table.php');
        (new \CreateBookingsTable)->up();

        $other = User::create(['name' => 'QA other agent', 'phone' => '000009999', 'role_id' => $agent->role_id, 'status' => 'active']);
        $start = Carbon::parse($date, 'Asia/Dushanbe')->startOfDay()->utc();
        foreach ([
            [$agent->id, $start->copy()->addHours(10)],
            [$agent->id, $start->copy()->addHours(12)],
            [$agent->id, $start->copy()->subSecond()],
            [$agent->id, $start->copy()->addDay()],
            [$other->id, $start->copy()->addHours(10)],
        ] as [$agentId, $time]) {
            DB::table('bookings')->insert([
                'property_id' => 1, 'agent_id' => $agentId,
                'start_time' => $time->toDateTimeString(), 'end_time' => $time->copy()->addMinutes(30)->toDateTimeString(),
                'status' => 'confirmed',
            ]);
        }
    }
}
