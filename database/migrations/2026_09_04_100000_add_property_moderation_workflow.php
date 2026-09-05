<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('publication_status', 24)->default('pending')->index();
            $table->string('deal_status', 24)->default('available')->index();
            $table->decimal('approved_price', 18, 2)->nullable();
            $table->decimal('approved_discount_price', 18, 2)->nullable();
            $table->decimal('approved_effective_price', 18, 2)->nullable();
            $table->string('approved_currency', 3)->nullable();
            $table->timestamp('price_approved_at')->nullable();
            $table->foreignId('price_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('approved_content_snapshot')->nullable();
            $table->foreignId('duplicate_of_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->unsignedBigInteger('moderation_version')->default(0);
        });

        Schema::create('property_moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('open');
            $table->boolean('blocking')->default(true);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->json('baseline_snapshot')->nullable();
            $table->json('proposed_snapshot')->nullable();
            $table->json('reason_codes')->nullable();
            $table->foreignId('parent_case_id')->nullable()->constrained('property_moderation_cases')->nullOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->index(['property_id', 'status', 'blocking'], 'property_moderation_open_idx');
            $table->index(['type', 'status', 'submitted_at'], 'property_moderation_queue_idx');
        });

        Schema::create('property_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained('property_moderation_cases')->cascadeOnDelete();
            $table->foreignId('candidate_property_id')->constrained('properties')->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->json('signals')->nullable();
            $table->json('candidate_snapshot')->nullable();
            $table->string('decision', 32)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_comment')->nullable();
            $table->timestamps();
            $table->unique(['moderation_case_id', 'candidate_property_id'], 'property_duplicate_candidate_unique');
            $table->index(['candidate_property_id', 'decision'], 'property_duplicate_decision_idx');
        });

        Schema::create('property_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('status', 24)->default('requested');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->text('request_comment')->nullable();
            $table->unsignedSmallInteger('requested_days')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('source', 24)->default('manual');
            $table->string('payment_reference')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->index(['property_id', 'status', 'starts_at', 'ends_at'], 'property_promotion_active_idx');
            $table->index(['status', 'requested_at'], 'property_promotion_queue_idx');
        });

        Schema::create('property_moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('moderation_case_id')->nullable()->constrained('property_moderation_cases')->nullOnDelete();
            $table->string('event_type', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');
            $table->index(['property_id', 'created_at']);
            $table->index(['moderation_case_id', 'created_at']);
        });

        Schema::create('employee_trust_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('moderation_case_id')->nullable()->constrained('property_moderation_cases')->nullOnDelete();
            $table->string('type', 64);
            $table->decimal('points_delta', 6, 2);
            $table->foreignId('confirmed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('confirmed_at');
            $table->text('comment')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('reverses_event_id')->nullable()->constrained('employee_trust_events')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
            $table->index(['moderation_case_id', 'type']);
        });

        Schema::create('property_moderation_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->string('route', 255);
            $table->string('status', 16)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('response_content_type', 128)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'property_moderation_idempotency_unique');
            $table->index(['status', 'created_at'], 'property_moderation_idempotency_status_idx');
        });

        $now = now();
        DB::table('properties')->orderBy('id')->chunkById(200, function ($properties) use ($now): void {
            foreach ($properties as $property) {
                $legacyStatus = (string) ($property->moderation_status ?? 'pending');
                $publicationStatus = match ($legacyStatus) {
                    'approved' => 'published',
                    'draft' => 'draft',
                    'rejected', 'denied' => 'rejected',
                    'deleted', 'sold', 'sold_by_owner', 'rented' => 'archived',
                    default => 'pending',
                };
                $dealStatus = match ($legacyStatus) {
                    'deposit' => 'deposit',
                    'sold' => 'sold',
                    'sold_by_owner' => 'sold_by_owner',
                    'rented' => 'rented',
                    'denied' => 'client_denied',
                    default => 'available',
                };
                $discount = isset($property->discount_price) && (float) $property->discount_price > 0
                    ? (float) $property->discount_price
                    : null;
                $effective = $discount ?? (float) ($property->price ?? 0);
                $published = $publicationStatus === 'published';
                $snapshot = null;
                if ($published) {
                    $snapshotFields = [
                        'title', 'description', 'type_id', 'status_id', 'location_id', 'repair_type_id',
                        'contract_type_id', 'document_type_id', 'price', 'discount_price', 'currency',
                        'offer_type', 'rooms', 'total_area', 'land_size', 'living_area', 'floor',
                        'total_floors', 'year_built', 'condition', 'construction_status',
                        'renovation_permission_status', 'apartment_type', 'has_garden', 'has_parking',
                        'is_mortgage_available', 'is_from_developer', 'landmark', 'latitude', 'longitude',
                        'district', 'address', 'developer_id', 'is_full_apartment', 'is_for_aura',
                        'parking_type_id', 'heating_type_id', 'owner_phone', 'owner_name', 'owner_client_id',
                    ];
                    $content = [];
                    foreach ($snapshotFields as $field) {
                        if (property_exists($property, $field)) {
                            $content[$field] = $property->{$field};
                        }
                    }
                    $content['effective_price'] = $effective;
                    $photos = Schema::hasTable('property_photos')
                        ? DB::table('property_photos')->where('property_id', $property->id)->orderBy('position')->orderBy('id')->get(['id', 'file_path', 'position'])
                        : collect();
                    $content['photos'] = $photos->map(fn ($photo) => [
                        'id' => (int) $photo->id,
                        'file_path' => $photo->file_path,
                        'hash' => is_file(\Storage::disk('public')->path($photo->file_path))
                            ? hash_file('sha256', \Storage::disk('public')->path($photo->file_path)) : null,
                        'position' => (int) $photo->position,
                    ])->values()->all();
                    $content['photo_ids'] = $photos->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                    if (Schema::hasTable('feature_property')) {
                        $content['feature_ids'] = DB::table('feature_property')->where('property_id', $property->id)->pluck('feature_id')->map(fn ($id) => (int) $id)->values()->all();
                    }
                    if (Schema::hasTable('property_tag')) {
                        $content['tag_ids'] = DB::table('property_tag')->where('property_id', $property->id)->pluck('tag_id')->map(fn ($id) => (int) $id)->values()->all();
                    }
                    $snapshot = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                DB::table('properties')->where('id', $property->id)->update([
                    'publication_status' => $publicationStatus,
                    'deal_status' => $dealStatus,
                    'approved_price' => $published ? $property->price : null,
                    'approved_discount_price' => $published ? $discount : null,
                    'approved_effective_price' => $published ? $effective : null,
                    'approved_currency' => $published ? $property->currency : null,
                    'price_approved_at' => $published ? $now : null,
                    'approved_content_snapshot' => $snapshot,
                ]);
                if ($published) {
                    DB::table('property_moderation_events')->insert([
                        'property_id' => $property->id,
                        'moderation_case_id' => null,
                        'event_type' => 'approved_snapshot_backfilled',
                        'actor_id' => null,
                        'actor_role' => 'system',
                        'payload' => json_encode([
                            'reason' => 'system_backfill',
                            'approved_price' => $property->price,
                            'approved_discount_price' => $discount,
                            'approved_effective_price' => $effective,
                            'approved_currency' => $property->currency,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'request_id' => null,
                        'ip_address' => null,
                        'user_agent' => null,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        // Existing VIP/urgent flags have no independent approval record. Move
        // them into the retrospective ROP+ queue instead of trusting the flag.
        DB::table('properties')
            ->whereIn('listing_type', ['vip', 'urgent'])
            ->orderBy('id')
            ->chunkById(200, function ($properties) use ($now): void {
                foreach ($properties as $property) {
                    $requesterId = $property->created_by ?: $property->agent_id;
                    $requesterExists = $requesterId && DB::table('users')->where('id', $requesterId)->exists();
                    if ($requesterExists && $property->publication_status === 'published') {
                        DB::table('property_promotions')->insert([
                            'property_id' => $property->id,
                            'type' => $property->listing_type,
                            'status' => 'requested',
                            'requested_by' => $requesterId,
                            'requested_at' => $now,
                            'request_comment' => 'Ретроспективная проверка продвижения после миграции.',
                            'requested_days' => 7,
                            'source' => 'system_backfill',
                            'version' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                    DB::table('properties')->where('id', $property->id)->update(['listing_type' => 'regular']);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_moderation_idempotency_keys');
        Schema::dropIfExists('employee_trust_events');
        Schema::dropIfExists('property_moderation_events');
        Schema::dropIfExists('property_promotions');
        Schema::dropIfExists('property_duplicate_candidates');
        Schema::dropIfExists('property_moderation_cases');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['publication_status']);
            $table->dropIndex(['deal_status']);
            $table->dropConstrainedForeignId('price_approved_by');
            $table->dropConstrainedForeignId('duplicate_of_property_id');
            $table->dropColumn([
                'publication_status',
                'deal_status',
                'approved_price',
                'approved_discount_price',
                'approved_effective_price',
                'approved_currency',
                'price_approved_at',
                'approved_content_snapshot',
                'moderation_version',
            ]);
        });
    }
};
