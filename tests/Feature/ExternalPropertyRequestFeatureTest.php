<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExternalPropertyRequest;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExternalPropertyRequestFeatureTest extends TestCase
{
    protected Role $externalRole;
    protected Role $agentRole;
    protected Role $mopRole;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_comment')->nullable();
            $table->string('contact_kind', 16)->default(Client::CONTACT_KIND_BUYER);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('building_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->decimal('living_area', 10, 2)->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->string('condition')->nullable();
            $table->string('moderation_status')->default('pending');
            $table->string('landmark')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('external_agent_id')->nullable();
            $table->unsignedBigInteger('external_property_request_id')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('listing_type')->default('regular');
            $table->string('owner_name')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->nullable();
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency', 3)->nullable();
            $table->timestamp('agent_paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('property_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('external_property_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_agent_id');
            $table->unsignedBigInteger('assigned_agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->string('status')->default(ExternalPropertyRequest::STATUS_SUBMITTED);
            $table->string('offer_type')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('landmark')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->decimal('living_area', 10, 2)->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->string('condition')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('owner_phone_normalized')->nullable();
            $table->text('external_comment')->nullable();
            $table->text('internal_comment')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('needs_info_comment')->nullable();
            $table->unsignedBigInteger('duplicate_property_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('external_property_request_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_property_request_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('external_property_request_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_property_request_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('comment')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->externalRole = Role::create(['name' => 'External', 'slug' => 'external_agent']);
        $this->agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $this->mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        \Illuminate\Support\Facades\DB::table('branches')->insert([
            [
                'id' => 1,
                'name' => 'Main',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Foreign',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert([
            [
                'id' => 1,
                'branch_id' => 1,
                'name' => 'Main Group',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'branch_id' => 2,
                'name' => 'Foreign Group',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_internal_request_actions_and_scopes_can_be_configured_for_a_custom_role(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $role = Role::create(['name' => 'Request reader', 'slug' => 'request_reader']);
        $actor = $this->user($role, '939111111', branchId: 1, branchGroupId: 1);
        $owner = $this->user($this->externalRole, '939111112', branchId: 1, branchGroupId: 1);
        $own = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted', 'branch_id' => 1, 'branch_group_id' => 1]);
        ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted', 'branch_id' => 1, 'branch_group_id' => 2]);
        ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted', 'branch_id' => 2, 'branch_group_id' => 3, 'assigned_agent_id' => $actor->id]);
        $grant = function (array $permissions) use ($actor, $role) {
            $role->forceFill(['permissions' => $permissions])->save();
            Sanctum::actingAs($actor->fresh());
        };
        $grant([]);
        $this->getJson('/api/external-agent-requests')->assertForbidden();
        foreach (['group' => 1, 'branch' => 2, 'assigned_or_org' => 3, 'all' => 3] as $scope => $total) {
            $grant(['external_requests.view', 'external_requests.scope.'.$scope]);
            $this->getJson('/api/external-agent-requests')->assertOk()->assertJsonPath('total', $total);
        }
        $this->getJson('/api/external-agent-requests/'.$own->id)->assertOk();
        $this->patchJson('/api/external-agent-requests/'.$own->id.'/assign', ['assigned_agent_id' => $actor->id])->assertForbidden();
        $this->patchJson('/api/external-agent-requests/'.$own->id.'/status', ['status' => 'rejected'])->assertForbidden();
        $this->getJson('/api/external-agent-requests/'.$own->id.'/prefill')->assertForbidden();
        $grant(['external_requests.status', 'external_requests.scope.group']);
        $this->patchJson('/api/external-agent-requests/'.$own->id.'/status', ['status' => 'needs_info', 'comment' => 'Нужны подробности'])->assertOk();
        $this->getJson('/api/external-agent-requests')->assertForbidden();
        $grant(['external_requests.view']);
        $this->getJson('/api/external-agent-requests')->assertOk()->assertJsonPath('total', 0);
        $grant([]);
        $this->getJson('/api/external-agent-requests/'.$own->id)->assertForbidden();
    }

    public function test_assignment_restrictions_and_recipient_eligibility_are_configurable(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $role = Role::create(['name' => 'Dispatcher', 'slug' => 'dispatcher']);
        $recipientRole = Role::create(['name' => 'Recipient', 'slug' => 'recipient']);
        $actor = $this->user($role, '939222221', branchId: 1, branchGroupId: 1);
        $recipient = $this->user($recipientRole, '939222222', branchId: 2, branchGroupId: 2);
        $owner = $this->user($this->externalRole, '939222223');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted', 'branch_id' => 1, 'branch_group_id' => 1]);
        $base = ['external_requests.assign', 'external_requests.scope.all', 'external_requests.receive'];
        $grant = function (array $permissions) use ($actor, $role) {
            $role->forceFill(['permissions' => $permissions])->save();
            Sanctum::actingAs($actor->fresh());
        };
        $url = '/api/external-agent-requests/'.$request->id.'/assign';
        $grant([...$base, 'external_requests.assign.self']);
        $this->patchJson($url, ['assigned_agent_id' => $recipient->id])->assertForbidden();
        $this->patchJson($url, ['assigned_agent_id' => null])->assertForbidden();
        $this->patchJson($url, ['assigned_agent_id' => $actor->id])->assertOk();
        $grant($base);
        $this->patchJson($url, ['assigned_agent_id' => $recipient->id])->assertUnprocessable();
        $recipientRole->forceFill(['permissions' => ['external_requests.receive']])->save();
        $this->patchJson($url, ['assigned_agent_id' => $recipient->id])->assertForbidden();
        $grant([...$base, 'external_requests.assign.across_zones']);
        $this->patchJson($url, ['assigned_agent_id' => $recipient->id])->assertOk();
        $recipientRole->forceFill(['permissions' => []])->save();
        $this->patchJson($url, ['assigned_agent_id' => $recipient->id])->assertUnprocessable();
        $this->assertSame($recipient->id, $request->fresh()->assigned_agent_id);
    }

    public function test_request_writes_recheck_revoked_permissions_on_cached_actor(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $role = Role::create(['name' => 'Writer', 'slug' => 'request_writer']);
        $role->forceFill(['permissions' => ['external_requests.assign', 'external_requests.status', 'external_requests.scope.all']])->save();
        $actor = $this->user($role, '939222224');
        $owner = $this->user($this->externalRole, '939222225');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted']);
        $actor->load('role');
        $role->forceFill(['permissions' => []])->save();
        $service = app(\App\Services\ExternalPropertyRequestService::class);
        foreach (['assign', 'status'] as $action) {
            try {
                $action === 'assign' ? $service->assign($request, $actor, null) : $service->changeStatus($request, $actor, 'rejected');
                $this->fail('Revoked permission allowed a write');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame('submitted', $request->fresh()->status);
        }
    }

    public function test_request_status_write_rechecks_current_organization(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $role = Role::create(['name' => 'Scoped writer', 'slug' => 'scoped_writer']);
        $role->forceFill(['permissions' => ['external_requests.status', 'external_requests.scope.group']])->save();
        $actor = $this->user($role, '939222226', branchId: 1, branchGroupId: 1);
        $owner = $this->user($this->externalRole, '939222227');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted', 'branch_id' => 1, 'branch_group_id' => 1]);
        $actor->load('role');
        User::query()->whereKey($actor->id)->update(['branch_group_id' => 2]);
        try {
            app(\App\Services\ExternalPropertyRequestService::class)->changeStatus($request, $actor, 'rejected');
            $this->fail('Former group allowed a write');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('submitted', $request->fresh()->status);
    }

    public function test_new_request_notifications_preserve_default_recipients_and_support_custom_roles(): void
    {
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        Schema::table('notifications', fn (Blueprint $table) => $table->unsignedBigInteger('branch_group_id')->nullable());
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $owner = $this->user($this->externalRole, '939333330');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'submitted', 'branch_id' => 1, 'branch_group_id' => 1]);
        $expected = [];
        $phone = 939333331;
        foreach (['admin' => [0, 1, 2, 3], 'superadmin' => [0, 1, 2, 3], 'rop' => [0, 1], 'branch_director' => [0, 1], 'mop' => [0, 3], 'agent' => [0, 1, 3], 'manager' => [0, 1, 3], 'operator' => [0, 1, 3], 'client' => [], 'intern' => []] as $slug => $eligible) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            foreach ([[1, 1], [1, 2], [2, 3], [2, 1]] as $index => [$branch, $group]) {
                $user = $this->user($role, (string) $phone++, branchId: $branch, branchGroupId: $group);
                if (in_array($index, $eligible, true)) $expected[] = $user->id;
            }
        }
        $custom = Role::create(['name' => 'Notification subscriber', 'slug' => 'notification_subscriber']);
        $subscriber = $this->user($custom, (string) $phone++, branchId: 1, branchGroupId: 2);
        $inactive = $this->user($this->agentRole, (string) $phone++, branchId: 1, branchGroupId: 1);
        $inactive->forceFill(['status' => User::STATUS_INACTIVE])->saveQuietly();
        $service = app(\App\Services\ExternalPropertyRequestService::class);
        $assertRecipients = function (array $ids) use ($service, $request, $owner) {
            \App\Models\Notification::query()->delete();
            $service->notifyInternalNewRequest($request, $owner);
            $actual = \App\Models\Notification::query()->pluck('user_id')->all();
            sort($ids);
            sort($actual);
            $this->assertSame($ids, $actual);
        };
        $assertRecipients($expected);
        $custom->forceFill(['permissions' => ['external_requests.notifications.branch']])->save();
        $assertRecipients([...$expected, $subscriber->id]);
        $custom->forceFill(['permissions' => ['external_requests.notifications.group']])->save();
        $assertRecipients($expected);
        $custom->forceFill(['permissions' => ['external_requests.notifications.all']])->save();
        $assertRecipients([...$expected, $subscriber->id]);
        $custom->forceFill(['permissions' => []])->save();
        $assertRecipients($expected);
    }

    public function test_own_request_permissions_are_independent_and_keep_ownership_boundaries(): void
    {
        \Illuminate\Support\Facades\Storage::fake(\App\Services\ExternalRequestMedia::DISK);
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $role = Role::create(['name' => 'Partner', 'slug' => 'custom_partner']);
        $actor = $this->user($role, '939444441', branchId: 1, branchGroupId: 1);
        $other = $this->user($this->externalRole, '939444442');
        $foreign = ExternalPropertyRequest::create(['external_agent_id' => $other->id, 'status' => 'draft']);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $payload = ['offer_type' => 'sale', 'type_id' => $type->id, 'price' => 85000, 'currency' => 'USD', 'owner_phone' => '900444441'];
        $grant = function (array $permissions) use ($role, $actor) {
            $role->forceFill(['permissions' => array_map(fn ($key) => 'external_requests.own.'.$key, $permissions)])->save();
            Sanctum::actingAs($actor->fresh());
        };
        $base = '/api/external/property-requests';
        $grant([]);
        $this->postJson($base, ['draft' => true])->assertForbidden();
        $grant(['create']);
        $created = $this->postJson($base, ['draft' => true, 'external_agent_id' => $other->id, 'branch_id' => 2, 'branch_group_id' => 2])->assertCreated()->json('id');
        $record = ExternalPropertyRequest::findOrFail($created);
        $this->assertSame($actor->id, $record->external_agent_id);
        $this->assertSame(1, $record->branch_id);
        $this->assertSame(1, $record->branch_group_id);
        $url = $base.'/'.$created;
        $this->postJson($base, $payload)->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->patchJson($url, $payload)->assertForbidden();
        $grant(['view']);
        $this->getJson($base)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($base.'/stats')->assertOk();
        $this->getJson($url)->assertOk();
        $this->getJson($base.'/'.$foreign->id)->assertForbidden();
        $this->patchJson($url, $payload)->assertForbidden();
        $grant(['edit']);
        $this->patchJson($url, $payload)->assertOk();
        $this->patchJson($base.'/'.$foreign->id, ['price' => 2])->assertForbidden();
        $this->postJson($url.'/submit')->assertForbidden();
        $record->update(['status' => 'needs_info']);
        $this->patchJson($url, ['price' => 87000])->assertForbidden();
        $this->assertSame('needs_info', $record->fresh()->status);
        $grant(['edit', 'submit']);
        $this->patchJson($url, ['price' => 85000])->assertOk()->assertJsonPath('status', 'submitted');
        $record->update(['status' => 'converted']);
        $this->patchJson($url, ['price' => 99999])->assertUnprocessable();
        $grant(['photos']);
        $this->postJson($url.'/photos', ['photos' => [\Illuminate\Http\UploadedFile::fake()->image('closed.jpg')]])->assertUnprocessable();
        $record->update(['status' => 'draft']);
        $grant(['submit']);
        $this->postJson($url.'/submit')->assertOk();
        $this->postJson($base.'/'.$foreign->id.'/submit')->assertForbidden();
        $record->update(['status' => 'draft']);
        $grant(['photos']);
        $photo = $this->postJson($url.'/photos', ['photos' => [\Illuminate\Http\UploadedFile::fake()->image('photo.jpg')]])->assertCreated()->json('data.0.id');
        $this->getJson('/api/external-request-photos/'.$photo)->assertNotFound();
        $this->deleteJson($base.'/'.$foreign->id.'/photos/'.$photo)->assertForbidden();
        $grant(['view']);
        $this->get('/api/external-request-photos/'.$photo)->assertOk();
        $this->deleteJson($url.'/photos/'.$photo)->assertForbidden();
        $grant(['photos']);
        $this->deleteJson($url.'/photos/'.$photo)->assertOk();
        $grant(['edit']);
        auth()->user()->load('role');
        $role->forceFill(['permissions' => []])->save();
        $this->patchJson($url, ['price' => 99999])->assertForbidden();
        $this->assertSame(85000.0, (float) $record->fresh()->price);
    }

    public function test_request_photos_are_private_and_require_current_parent_access(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::fake(\App\Services\ExternalRequestMedia::DISK);
        $owner = $this->user($this->externalRole, '939000001');
        $other = $this->user($this->externalRole, '939000002');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'draft']);
        Sanctum::actingAs($owner);
        $uploaded = $this->postJson('/api/external/property-requests/'.$request->id.'/photos', [
            'photos' => [\Illuminate\Http\UploadedFile::fake()->image('private.png')],
        ])->assertCreated()->assertJsonMissingPath('data.0.file_path')->json('data.0');
        $photo = \App\Models\ExternalPropertyRequestPhoto::findOrFail($uploaded['id']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($photo->file_path);
        \Illuminate\Support\Facades\Storage::disk(\App\Services\ExternalRequestMedia::DISK)->assertExists($photo->file_path);
        $url = '/api/external-request-photos/'.$photo->id;
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->get($url)->headers->get('Cache-Control'));
        Sanctum::actingAs($other);
        $this->getJson($url)->assertNotFound();
        Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $role = Role::create(['name' => 'Media reader', 'slug' => 'media_reader']);
        $reader = $this->user($role, '939000003');
        $role->forceFill(['permissions' => ['external_requests.view', 'external_requests.scope.all']])->save();
        Sanctum::actingAs($reader->fresh());
        $this->get($url)->assertOk();
        $role->forceFill(['permissions' => ['external_requests.scope.all']])->save();
        Sanctum::actingAs($reader->fresh());
        $this->getJson($url)->assertNotFound();
        Sanctum::actingAs($owner);
        $request->delete();
        $this->getJson($url)->assertNotFound();
    }

    public function test_legacy_request_photo_migration_verifies_and_removes_the_public_copy(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::fake(\App\Services\ExternalRequestMedia::DISK);
        $owner = $this->user($this->externalRole, '939000003');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'status' => 'draft']);
        $path = 'external-property-requests/'.$request->id.'/legacy.png';
        $bytes = 'private legacy photo bytes';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);
        $request->photos()->create(['file_path' => $path]);
        $this->artisan('rop-groups:privatize-request-photos')->assertSuccessful();
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
        \Illuminate\Support\Facades\Storage::disk(\App\Services\ExternalRequestMedia::DISK)->assertMissing($path);
        $this->artisan('rop-groups:privatize-request-photos', ['--apply' => true])->assertSuccessful();
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);
        $this->assertSame($bytes, \Illuminate\Support\Facades\Storage::disk(\App\Services\ExternalRequestMedia::DISK)->get($path));
        $this->artisan('rop-groups:privatize-request-photos', ['--apply' => true])->assertSuccessful();
    }

    public function test_rop_cannot_use_unadapted_external_agent_management_or_media(): void
    {
        $owner = $this->user($this->externalRole, '939000004');
        $request = ExternalPropertyRequest::create(['external_agent_id' => $owner->id, 'branch_id' => 1, 'branch_group_id' => 1]);
        $photo = $request->photos()->create(['file_path' => 'external-property-requests/private.png']);
        $rop = $this->user(Role::create(['name' => 'ROP', 'slug' => 'rop']), '939000005', branchId: 1, branchGroupId: 1);
        Sanctum::actingAs($rop);
        foreach (['/api/external-agent-requests', '/api/external-agent-requests/stats', '/api/external-agent-requests/leaderboard',
            '/api/external-agent-requests/'.$request->id, '/api/external-request-photos/'.$photo->id,
            '/api/crm/reports/performance?role_type=operator'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ACTION');
        }
    }

    public function test_external_request_rejects_incomplete_owner_phone_even_in_draft(): void
    {
        Sanctum::actingAs($this->user($this->externalRole, '930555001'));
        $this->postJson('/api/external/property-requests?draft=1', ['owner_phone' => '+99290123456'])
            ->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['owner_phone']]]);
        $this->postJson('/api/external/property-requests?draft=1', ['owner_phone' => '+77011234567'])
            ->assertCreated();
        $draft = ExternalPropertyRequest::firstOrFail();
        $this->patchJson('/api/external/property-requests/'.$draft->id, ['owner_phone' => '+7912'])
            ->assertUnprocessable();
        $this->assertSame('+77011234567', $draft->fresh()->owner_phone);
    }

    public function test_external_agent_creates_and_sees_only_own_requests(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000001');
        $otherExternalAgent = $this->user($this->externalRole, '930000002');
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        ExternalPropertyRequest::create([
            'external_agent_id' => $otherExternalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 100000,
            'currency' => 'USD',
            'owner_phone' => '900000000',
        ]);

        Sanctum::actingAs($externalAgent);

        $this->postJson('/api/external/property-requests', [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'district' => 'Сино',
            'owner_name' => 'Соҳиб',
            'owner_phone' => '900000001',
        ])->assertCreated()
            ->assertJsonPath('status', ExternalPropertyRequest::STATUS_SUBMITTED);

        $response = $this->getJson('/api/external/property-requests')->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame(ExternalPropertyRequest::STATUS_SUBMITTED, $response->json('data.0.status'));
        $this->assertSame($externalAgent->id, ExternalPropertyRequest::query()->latest('id')->first()->external_agent_id);
    }

    public function test_external_agent_cannot_override_branch_scope(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000003', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        Sanctum::actingAs($externalAgent);

        $this->postJson('/api/external/property-requests', [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'branch_id' => 999,
            'branch_group_id' => 999,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000003',
        ])->assertCreated();

        $request = ExternalPropertyRequest::query()->firstOrFail();

        $this->assertSame(1, $request->branch_id);
        $this->assertSame(1, $request->branch_group_id);
    }

    public function test_external_agent_response_hides_internal_comments(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000004');
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000004',
            'internal_comment' => 'private internal note',
        ]);
        $request->logs()->create([
            'action' => 'status_changed',
            'from_status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'to_status' => ExternalPropertyRequest::STATUS_ASSIGNED,
            'comment' => 'private status note',
        ]);

        Sanctum::actingAs($externalAgent);

        $response = $this->getJson("/api/external/property-requests/{$request->id}")
            ->assertOk()
            ->assertJsonMissingPath('internal_comment');

        $this->assertStringNotContainsString('private internal note', $response->getContent());
        $this->assertStringNotContainsString('private status note', $response->getContent());
    }

    public function test_empty_draft_cannot_be_submitted_until_required_fields_exist(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000005');
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        Sanctum::actingAs($externalAgent);

        $draftId = $this->postJson('/api/external/property-requests?draft=1', [])
            ->assertCreated()
            ->assertJsonPath('status', ExternalPropertyRequest::STATUS_DRAFT)
            ->json('id');

        $this->postJson("/api/external/property-requests/{$draftId}/submit")
            ->assertStatus(422)
            ->assertJsonPath('details.errors.offer_type.0', 'The Тип предложения field is required.')
            ->assertJsonPath('details.errors.type_id.0', 'The Тип недвижимости field is required.')
            ->assertJsonPath('details.errors.price.0', 'The Цена field is required.')
            ->assertJsonPath('details.errors.currency.0', 'The Валюта field is required.')
            ->assertJsonPath('details.errors.owner_phone.0', 'The Телефон владельца field is required.');

        $this->patchJson("/api/external/property-requests/{$draftId}", [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000005',
        ])->assertOk();

        $this->postJson("/api/external/property-requests/{$draftId}/submit")
            ->assertOk()
            ->assertJsonPath('status', ExternalPropertyRequest::STATUS_SUBMITTED);
    }

    public static function conversionRoles(): array
    {
        return [['agent'], ['custom_converter'], ['client'], ['external_agent'], ['intern']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conversionRoles')]
    public function test_internal_agent_converts_external_request_to_property_with_source(string $actorSlug): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::fake(\App\Services\ExternalRequestMedia::DISK);
        $externalAgent = $this->user($this->externalRole, '930000011', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000012', branchId: 1, branchGroupId: 1);
        if ($actorSlug !== 'agent') {
            Schema::table('roles', fn (Blueprint $table) => $table->json('permissions')->nullable());
            $role = Role::firstOrCreate(['slug' => $actorSlug], ['name' => $actorSlug]);
            $role->forceFill(['permissions' => ['properties.create', 'external_requests.convert', 'external_requests.scope.all']])->save();
            $internalAgent->forceFill(['role_id' => $role->id])->saveQuietly();
        }

        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'rooms' => 2,
            'district' => 'Сино',
            'owner_name' => 'Соҳиб',
            'owner_phone' => '900000001',
            'owner_phone_normalized' => '992900000001',
            'external_comment' => 'Срочно продает',
        ]);

        $sourcePath = 'external-property-requests/'.$request->id.'/private.png';
        \Illuminate\Support\Facades\Storage::disk(\App\Services\ExternalRequestMedia::DISK)->put($sourcePath, 'private-photo');
        $request->photos()->create(['file_path' => $sourcePath]);
        Sanctum::actingAs($internalAgent);

        $this->postJson("/api/external-agent-requests/{$request->id}/convert", [
            'status_id' => $status->id,
        ])->assertCreated()
            ->assertJsonPath('data.external_agent_id', $externalAgent->id)
            ->assertJsonPath('data.external_property_request_id', $request->id)
            ->assertJsonPath('data.source_type', ExternalPropertyRequest::SOURCE_TYPE);

        $request->refresh();
        $property = Property::query()->firstOrFail();
        $client = Client::query()->firstOrFail();

        $this->assertSame(ExternalPropertyRequest::STATUS_CONVERTED, $request->status);
        $this->assertSame($property->id, $request->property_id);
        $this->assertSame($externalAgent->id, $property->external_agent_id);
        $this->assertSame($request->id, $property->external_property_request_id);
        $this->assertSame(ExternalPropertyRequest::SOURCE_TYPE, $property->source_type);
        $this->assertSame($client->id, $property->owner_client_id);
        $this->assertSame(Client::CONTACT_KIND_SELLER, $client->contact_kind);
        $photo = $property->photos()->firstOrFail();
        $this->assertNotSame($sourcePath, $photo->file_path);
        $this->assertSame('private-photo', \Illuminate\Support\Facades\Storage::disk('public')->get($photo->file_path));
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($sourcePath);
    }

    public function test_duplicate_request_requires_force_before_conversion(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000021', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000022', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $existing = Property::create([
            'title' => 'Existing property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 90000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'owner_phone' => '+992 900 000 777',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
        ]);

        Sanctum::actingAs($externalAgent);

        $createdResponse = $this->postJson('/api/external/property-requests', [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000777',
        ])->assertCreated();

        $createdResponse->assertJsonPath('status', ExternalPropertyRequest::STATUS_DUPLICATE);
        $createdResponse->assertJsonPath('duplicate_property_id', $existing->id);

        $requestId = $createdResponse->json('id');
        Sanctum::actingAs($internalAgent);

        $converted = $this->postJson("/api/external-agent-requests/{$requestId}/convert", [
            'status_id' => $status->id,
        ])->assertCreated();
        $converted->assertJsonPath('data.moderation_status', 'pending');

        $this->postJson("/api/external-agent-requests/{$requestId}/convert", [
            'status_id' => $status->id,
            'force' => true,
        ])->assertUnprocessable();
    }

    public function test_internal_agent_cannot_assign_request_to_external_agent(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000031', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000032', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000031',
        ]);

        Sanctum::actingAs($internalAgent);

        $this->patchJson("/api/external-agent-requests/{$request->id}/assign", [
            'assigned_agent_id' => $externalAgent->id,
        ])->assertForbidden();
    }

    public function test_agents_and_mops_can_accept_scoped_requests_and_convert_them(): void
    {
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        foreach ([
            [$this->agentRole, '930000033', '930000034'],
            [$this->mopRole, '930000035', '930000036'],
        ] as [$internalRole, $externalPhone, $internalPhone]) {
            $externalAgent = $this->user($this->externalRole, $externalPhone, branchId: 1, branchGroupId: 1);
            $internalUser = $this->user($internalRole, $internalPhone, branchId: 1, branchGroupId: 1);
            $externalRequest = ExternalPropertyRequest::create([
                'external_agent_id' => $externalAgent->id,
                'branch_id' => 1,
                'branch_group_id' => 1,
                'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
                'offer_type' => 'sale',
                'type_id' => $type->id,
                'price' => 85000,
                'currency' => 'USD',
                'owner_phone' => $externalPhone,
            ]);

            Sanctum::actingAs($internalUser);

            $otherInternalUser = $this->user($this->agentRole, (string) ((int) $internalPhone + 100), branchId: 1, branchGroupId: 1);
            $this->patchJson("/api/external-agent-requests/{$externalRequest->id}/assign", [
                'assigned_agent_id' => $otherInternalUser->id,
            ])->assertForbidden();

            $this->patchJson("/api/external-agent-requests/{$externalRequest->id}/assign", [
                'assigned_agent_id' => $internalUser->id,
            ])->assertOk()
                ->assertJsonPath('assigned_agent_id', $internalUser->id)
                ->assertJsonPath('status', ExternalPropertyRequest::STATUS_ASSIGNED);

            $this->postJson("/api/external-agent-requests/{$externalRequest->id}/convert", [
                'status_id' => $status->id,
            ])->assertCreated()
                ->assertJsonPath('data.agent_id', $internalUser->id)
                ->assertJsonPath('data.external_agent_id', $externalAgent->id)
                ->assertJsonPath('data.source_type', ExternalPropertyRequest::SOURCE_TYPE);
        }
    }

    public function test_external_agent_cannot_create_property_directly(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000041', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        Sanctum::actingAs($externalAgent);

        $this->postJson('/api/properties', [
            'title' => 'Direct property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
        ])->assertForbidden();
    }

    public function test_property_list_can_filter_external_agent_source(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000051', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000052', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $externalProperty = Property::create([
            'title' => 'External sourced',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
        ]);

        Property::create([
            'title' => 'Regular property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 75000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
        ]);

        Sanctum::actingAs($internalAgent);

        $response = $this->getJson('/api/properties?source_type=external_agent')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($externalProperty->id, $response->json('data.0.id'));
    }

    public function test_property_show_exposes_external_source_only_for_authenticated_users(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000061', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000062', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);
        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_CONVERTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000061',
            'submitted_at' => now(),
            'converted_at' => now(),
        ]);
        $property = Property::create([
            'title' => 'External sourced',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'external_property_request_id' => $request->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
        ]);
        $request->update(['property_id' => $property->id]);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonMissingPath('external_source');

        Sanctum::actingAs($internalAgent);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('external_source.source_type', ExternalPropertyRequest::SOURCE_TYPE)
            ->assertJsonPath('external_source.external_agent_id', $externalAgent->id)
            ->assertJsonPath('external_source.external_property_request_id', $request->id);
    }

    public function test_external_agent_stats_are_scoped_to_own_requests(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000071', branchId: 1, branchGroupId: 1);
        $otherExternalAgent = $this->user($this->externalRole, '930000072', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000073', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000071',
        ]);

        $converted = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_CONVERTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 95000,
            'currency' => 'USD',
            'owner_phone' => '900000074',
        ]);
        $property = Property::create([
            'title' => 'Published external',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 95000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'external_property_request_id' => $converted->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
        ]);
        $converted->update(['property_id' => $property->id]);

        ExternalPropertyRequest::create([
            'external_agent_id' => $otherExternalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 75000,
            'currency' => 'USD',
            'owner_phone' => '900000075',
        ]);

        Sanctum::actingAs($externalAgent);

        $this->getJson('/api/external/property-requests/stats')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.submitted', 1)
            ->assertJsonPath('by_status.converted', 1)
            ->assertJsonPath('converted.total', 1)
            ->assertJsonPath('converted.published', 1);
    }

    public function test_internal_stats_respect_agent_scope_and_filters(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000081', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000082', branchId: 1, branchGroupId: 1);
        $foreignAgent = $this->user($this->agentRole, '930000083', branchId: 2, branchGroupId: 2);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'assigned_agent_id' => $internalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_ASSIGNED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000081',
        ]);
        ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'assigned_agent_id' => $foreignAgent->id,
            'branch_id' => 2,
            'branch_group_id' => 2,
            'status' => ExternalPropertyRequest::STATUS_ASSIGNED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 75000,
            'currency' => 'USD',
            'owner_phone' => '900000082',
        ]);

        Sanctum::actingAs($internalAgent);

        $this->getJson('/api/external-agent-requests/stats')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('by_status.assigned', 1);

        $this->getJson('/api/external-agent-requests/stats?status=submitted')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('by_status.assigned', 0);
    }

    public function test_internal_leaderboard_respects_scope_and_sorts_by_conversions(): void
    {
        $externalAgentA = $this->user($this->externalRole, '930000091', branchId: 1, branchGroupId: 1);
        $externalAgentB = $this->user($this->externalRole, '930000092', branchId: 1, branchGroupId: 1);
        $externalAgentForeign = $this->user($this->externalRole, '930000093', branchId: 2, branchGroupId: 2);
        $internalAgent = $this->user($this->agentRole, '930000094', branchId: 1, branchGroupId: 1);
        $foreignInternalAgent = $this->user($this->agentRole, '930000095', branchId: 2, branchGroupId: 2);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $convertedA = $this->externalRequestForLeaderboard($externalAgentA, $internalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000091');
        $this->propertyForConvertedRequest($convertedA, $externalAgentA, $internalAgent, $type, $status, 'approved');
        $this->externalRequestForLeaderboard($externalAgentA, $internalAgent, $type, ExternalPropertyRequest::STATUS_SUBMITTED, '900000092');

        $convertedB1 = $this->externalRequestForLeaderboard($externalAgentB, $internalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000093');
        $this->propertyForConvertedRequest($convertedB1, $externalAgentB, $internalAgent, $type, $status, 'approved');
        $convertedB2 = $this->externalRequestForLeaderboard($externalAgentB, $internalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000094');
        $this->propertyForConvertedRequest($convertedB2, $externalAgentB, $internalAgent, $type, $status, 'sold');

        $foreignConverted = $this->externalRequestForLeaderboard($externalAgentForeign, $foreignInternalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000095', branchId: 2, branchGroupId: 2);
        $this->propertyForConvertedRequest($foreignConverted, $externalAgentForeign, $foreignInternalAgent, $type, $status, 'approved');

        Sanctum::actingAs($internalAgent);

        $this->getJson('/api/external-agent-requests/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.0.external_agent_id', $externalAgentB->id)
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.0.converted', 2)
            ->assertJsonPath('data.0.closed_deal', 1)
            ->assertJsonPath('data.1.external_agent_id', $externalAgentA->id)
            ->assertJsonPath('data.1.total', 2)
            ->assertJsonPath('data.1.converted', 1)
            ->assertJsonMissing(['external_agent_id' => $externalAgentForeign->id]);
    }

    private function user(Role $role, string $phone, ?int $branchId = null, ?int $branchGroupId = null): User
    {
        return User::create([
            'name' => 'User ' . $phone,
            'phone' => $phone,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'branch_group_id' => $branchGroupId,
            'status' => 'active',
        ]);
    }

    private function externalRequestForLeaderboard(
        User $externalAgent,
        User $assignedAgent,
        PropertyType $type,
        string $status,
        string $ownerPhone,
        int $branchId = 1,
        int $branchGroupId = 1
    ): ExternalPropertyRequest {
        return ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'assigned_agent_id' => $assignedAgent->id,
            'branch_id' => $branchId,
            'branch_group_id' => $branchGroupId,
            'status' => $status,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => $ownerPhone,
        ]);
    }

    private function propertyForConvertedRequest(
        ExternalPropertyRequest $request,
        User $externalAgent,
        User $internalAgent,
        PropertyType $type,
        PropertyStatus $status,
        string $moderationStatus
    ): Property {
        $property = Property::create([
            'title' => 'Converted property ' . $request->id,
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => $moderationStatus,
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'external_property_request_id' => $request->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
            'branch_id' => $internalAgent->branch_id,
            'branch_group_id' => $internalAgent->branch_group_id,
        ]);

        $request->update(['property_id' => $property->id]);

        return $property;
    }
}
