<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['location_id', 'slug']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('district')->constrained('districts')->nullOnDelete();
            $table->timestamp('listed_at')->nullable()->after('listing_updated_at')->index();
            $table->unsignedTinyInteger('liquidity_score')->nullable()->index();
            $table->string('liquidity_category', 24)->nullable()->index();
            $table->unsignedTinyInteger('liquidity_confidence')->nullable();
            $table->string('price_position', 24)->nullable()->index();
            $table->decimal('price_delta_pct', 8, 2)->nullable();
            $table->unsignedTinyInteger('promotion_priority_score')->nullable()->index();
            $table->string('promotion_eligibility', 24)->nullable()->index();
            $table->boolean('liquidity_business_priority')->default(false)->index();
            $table->text('liquidity_business_priority_comment')->nullable();
            $table->foreignId('liquidity_business_priority_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('liquidity_business_priority_at')->nullable();
            $table->timestamp('liquidity_calculated_at')->nullable();
            $table->string('liquidity_model_version', 48)->nullable();
        });

        Schema::table('client_needs', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('district')->constrained('districts')->nullOnDelete();
        });

        if (Schema::hasTable('new_buildings') && ! Schema::hasColumn('new_buildings', 'district_id')) {
            Schema::table('new_buildings', function (Blueprint $table) {
                $table->foreignId('district_id')->nullable()->after('district')->constrained('districts')->nullOnDelete();
            });
        }

        Schema::create('district_market_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date');
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->foreignId('property_type_id')->constrained('property_types')->cascadeOnDelete();
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->boolean('is_from_developer')->default(false);
            $table->string('currency', 3);
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->unsignedSmallInteger('median_dom')->nullable();
            $table->decimal('median_price_sqm', 15, 2)->nullable();
            $table->decimal('sell_through_rate', 8, 4)->default(0);
            $table->timestamps();
            $table->unique(
                ['metric_date', 'district_id', 'property_type_id', 'rooms', 'is_from_developer', 'currency'],
                'district_market_segment_unique'
            );
        });

        Schema::create('property_liquidity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('category', 24);
            $table->unsignedTinyInteger('confidence_score');
            $table->string('confidence_level', 16);
            $table->unsignedSmallInteger('predicted_days_from')->nullable();
            $table->unsignedSmallInteger('predicted_days_to')->nullable();
            $table->unsignedTinyInteger('district_market_score');
            $table->unsignedTinyInteger('price_score');
            $table->unsignedTinyInteger('demand_score');
            $table->unsignedTinyInteger('apartment_fit_score');
            $table->unsignedTinyInteger('interest_score')->nullable();
            $table->string('price_position', 24);
            $table->decimal('price_delta_pct', 8, 2);
            $table->json('cohort_definition');
            $table->unsignedInteger('cohort_sold_count')->default(0);
            $table->unsignedInteger('cohort_active_count')->default(0);
            $table->unsignedSmallInteger('cohort_median_dom')->nullable();
            $table->decimal('cohort_median_price_sqm', 15, 2);
            $table->json('factors')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('market')->nullable();
            $table->json('interest')->nullable();
            $table->string('model_version', 48);
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->index(['property_id', 'calculated_at']);
        });

        Schema::create('property_social_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('channel', 24)->nullable();
            $table->string('status', 24)->default('recommended')->index();
            $table->unsignedTinyInteger('priority_score_snapshot')->nullable();
            $table->unsignedTinyInteger('liquidity_score_snapshot')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('published_url')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('skip_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
            $table->index(['status', 'channel', 'planned_at'], 'property_promotions_queue_idx');
            $table->index(['property_id', 'channel', 'published_at'], 'property_promotions_rotation_idx');
        });

        Schema::create('property_liquidity_priority_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->text('comment');
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['property_id', 'created_at']);
        });

        Schema::create('property_view_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('views_count');
            $table->unsignedInteger('views_delta')->default(0);
            $table->unsignedInteger('active_days')->default(0);
            $table->timestamps();
            $table->unique(['property_id', 'date']);
        });

        Schema::create('property_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['property_id', 'changed_at']);
        });

        Schema::create('property_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->decimal('discount_price', 15, 2)->nullable();
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 15, 6)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['property_id', 'changed_at']);
        });

        $this->backfillDistricts();

        DB::table('properties')->whereNull('listed_at')->update(['listed_at' => DB::raw('created_at')]);
    }

    private function backfillDistricts(): void
    {
        DB::table('locations')
            ->whereNotNull('district')
            ->where('district', '!=', '')
            ->get(['id', 'district'])
            ->each(function (object $location): void {
                $name = trim((string) $location->district);
                DB::table('districts')->updateOrInsert(
                    ['location_id' => $location->id, 'slug' => Str::slug($name) ?: md5(mb_strtolower($name))],
                    ['name' => $name, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
                );
            });

        foreach (['properties', 'client_needs', 'new_buildings'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'district_id') || ! Schema::hasColumn($table, 'location_id')) {
                continue;
            }

            DB::table($table)
                ->whereNotNull('location_id')
                ->whereNotNull('district')
                ->where('district', '!=', '')
                ->select(['location_id', 'district'])
                ->distinct()
                ->orderBy('location_id')
                ->get()
                ->each(function (object $row): void {
                    $name = trim((string) $row->district);
                    $slug = Str::slug($name) ?: md5(mb_strtolower($name));

                    DB::table('districts')->updateOrInsert(
                        ['location_id' => $row->location_id, 'slug' => $slug],
                        ['name' => $name, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
                    );
                });

            $districts = DB::table('districts')->get(['id', 'location_id', 'name']);
            foreach ($districts as $district) {
                DB::table($table)
                    ->where('location_id', $district->location_id)
                    ->whereRaw('LOWER(TRIM(district)) = ?', [mb_strtolower(trim((string) $district->name))])
                    ->update(['district_id' => $district->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_price_history');
        Schema::dropIfExists('property_status_history');
        Schema::dropIfExists('property_view_daily_stats');
        Schema::dropIfExists('property_liquidity_priority_logs');
        Schema::dropIfExists('property_social_promotions');
        Schema::dropIfExists('property_liquidity_snapshots');
        Schema::dropIfExists('district_market_metrics');

        if (Schema::hasTable('new_buildings') && Schema::hasColumn('new_buildings', 'district_id')) {
            Schema::table('new_buildings', fn (Blueprint $table) => $table->dropConstrainedForeignId('district_id'));
        }

        Schema::table('client_needs', fn (Blueprint $table) => $table->dropConstrainedForeignId('district_id'));
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('liquidity_business_priority_by');
            $table->dropColumn([
                'listed_at', 'liquidity_score', 'liquidity_category', 'liquidity_confidence',
                'price_position', 'price_delta_pct', 'promotion_priority_score',
                'promotion_eligibility', 'liquidity_business_priority',
                'liquidity_business_priority_comment', 'liquidity_business_priority_at',
                'liquidity_calculated_at', 'liquidity_model_version',
            ]);
        });
        Schema::dropIfExists('districts');
    }
};
