<?php

use App\Models\ClientType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_business')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_need_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_need_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('client_type_id')
                ->nullable()
                ->after('responsible_agent_id')
                ->constrained('client_types')
                ->nullOnDelete();
        });

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('client_need_types')->restrictOnDelete();
            $table->foreignId('status_id')->constrained('client_need_statuses')->restrictOnDelete();
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->enum('currency', ['TJS', 'USD'])->default('TJS');
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('district')->nullable();
            $table->foreignId('property_type_id')->nullable()->constrained('property_types')->nullOnDelete();
            $table->unsignedInteger('rooms_from')->nullable();
            $table->unsignedInteger('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $now = now();

        DB::table('client_types')->upsert([
            [
                'name' => 'Физлицо',
                'slug' => ClientType::SLUG_INDIVIDUAL,
                'is_business' => false,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Бизнесмен',
                'slug' => ClientType::SLUG_BUSINESS_OWNER,
                'is_business' => true,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'is_business', 'sort_order', 'is_active', 'updated_at']);

        DB::table('client_need_types')->upsert([
            ['name' => 'Покупка', 'slug' => 'buy', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Аренда', 'slug' => 'rent', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Продажа', 'slug' => 'sell', 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Инвестиция', 'slug' => 'invest', 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['slug'], ['name', 'sort_order', 'is_active', 'updated_at']);

        DB::table('client_need_statuses')->upsert([
            ['name' => 'Новая', 'slug' => 'new', 'is_closed' => false, 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'В работе', 'slug' => 'in_progress', 'is_closed' => false, 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Ожидание', 'slug' => 'waiting', 'is_closed' => false, 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Закрыта успешно', 'slug' => 'closed_success', 'is_closed' => true, 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Закрыта без результата', 'slug' => 'closed_lost', 'is_closed' => true, 'sort_order' => 50, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['slug'], ['name', 'is_closed', 'sort_order', 'is_active', 'updated_at']);

        $individualTypeId = DB::table('client_types')->where('slug', ClientType::SLUG_INDIVIDUAL)->value('id');
        $businessTypeId = DB::table('client_types')->where('slug', ClientType::SLUG_BUSINESS_OWNER)->value('id');

        if ($individualTypeId) {
            DB::table('clients')
                ->whereNull('client_type_id')
                ->update(['client_type_id' => $individualTypeId]);
        }

        if ($businessTypeId) {
            DB::table('clients')
                ->whereIn('id', function ($query) {
                    $query->select('owner_client_id')
                        ->from('properties')
                        ->whereNotNull('owner_client_id')
                        ->where('is_business_owner', true);
                })
                ->update(['client_type_id' => $businessTypeId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_needs');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_type_id');
        });

        Schema::dropIfExists('client_need_statuses');
        Schema::dropIfExists('client_need_types');
        Schema::dropIfExists('client_types');
    }
};
