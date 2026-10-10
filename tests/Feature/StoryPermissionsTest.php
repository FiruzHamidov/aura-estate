<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoryPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->string('description')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('role_id');
            $table->string('status')->default('active'); $table->string('photo')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable(); $table->timestamps();
        });
        foreach (Role::systemRoles() as $role) Role::create($role);
        Role::create(['name' => 'Owner', 'slug' => 'owner']);
        Role::create(['name' => 'Existing custom', 'slug' => 'existing_custom']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        foreach (['000_create_stories', '100_create_story_items', '200_create_story_attachments'] as $migration) {
            (require database_path('migrations/2026_05_01_190'.$migration.'_table.php'))->up();
        }
    }

    private function actor(string $slug, ?int $branch = 1, ?int $group = 10): User
    {
        // Fixture creation is independent of whichever actor the previous request authenticated.
        $user = User::withoutEvents(fn () => User::create(['name' => $slug, 'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'status' => 'active', 'branch_id' => $branch, 'branch_group_id' => $group]));
        Sanctum::actingAs($user);
        return $user;
    }

    private function story(User $author): Story
    {
        return Story::create(['user_id' => $author->id, 'type' => Story::TYPE_MEDIA, 'status' => Story::STATUS_DRAFT]);
    }

    public function test_persisted_defaults_preserve_personal_story_access_for_existing_roles(): void
    {
        foreach (Role::all() as $role) {
            $actor = $this->actor($role->slug);
            $this->getJson('/api/my/stories')->assertStatus($role->slug === 'rop' ? 403 : 200);
            $response = $this->postJson('/api/stories', ['type' => 'media', 'status' => 'active']);
            if (in_array($role->slug, ['client', 'rop'], true)) {
                $response->assertForbidden();
                continue;
            }
            $response->assertCreated();
            $path = '/api/stories/'.$response->json('id');
            $this->patchJson($path, ['caption' => 'Updated'])->assertOk();
            $this->patchJson($path.'/status', ['action' => 'archive'])->assertOk();
            $this->deleteJson($path)->assertOk();
            $this->assertDatabaseHas('stories', ['user_id' => $actor->id, 'status' => Story::STATUS_DELETED]);
        }
    }

    public function test_client_grants_are_independent_and_publication_cannot_bypass_status_permission(): void
    {
        $actor = $this->actor('client');
        $actor->role->forceFill(['permissions' => ['stories.create']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->postJson('/api/stories', ['type' => 'media', 'status' => 'active'])->assertForbidden();
        $this->assertDatabaseCount('stories', 0);
        $response = $this->postJson('/api/stories', ['type' => 'media'])->assertCreated();
        $path = '/api/stories/'.$response->json('id');
        $this->getJson('/api/my/stories')->assertForbidden();
        $this->patchJson($path, ['caption' => 'Denied'])->assertForbidden();
        $this->deleteJson($path)->assertForbidden();
        $this->patchJson($path.'/status', ['action' => 'republish'])->assertForbidden();
        $actor->role->forceFill(['permissions' => ['stories.own.view', 'stories.update', 'stories.status']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/my/stories')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($path, ['caption' => 'Allowed'])->assertOk();
        $this->patchJson($path.'/status', ['action' => 'republish'])->assertOk();
        $this->deleteJson($path)->assertForbidden();
        $this->postJson('/api/stories', ['type' => 'media'])->assertForbidden();
    }

    public function test_moderation_scope_and_status_permission_apply_to_both_status_routes(): void
    {
        $local = $this->story($this->actor('agent', 1, 10));
        $sameBranch = $this->story($this->actor('agent', 1, 20));
        $remote = $this->story($this->actor('agent', 2, 30));
        $actor = $this->actor('client');
        $role = $actor->role;
        $role->forceFill(['permissions' => ['stories.moderation.view', 'stories.moderation.scope.group']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/admin/stories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $local->id);
        foreach (['stories', 'admin/stories'] as $route) {
            $this->patchJson('/api/'.$route.'/'.$local->id.'/status', ['action' => 'republish'])->assertForbidden();
        }
        $role->forceFill(['permissions' => ['stories.moderation.view', 'stories.moderation.scope.branch', 'stories.moderation.status']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/admin/stories')->assertOk()->assertJsonCount(2, 'data');
        foreach (['stories', 'admin/stories'] as $route) {
            $this->patchJson('/api/'.$route.'/'.$sameBranch->id.'/status', ['action' => 'republish'])->assertOk();
            $this->patchJson('/api/'.$route.'/'.$remote->id.'/status', ['action' => 'republish'])->assertForbidden();
        }
        $this->patchJson('/api/stories/'.$local->id, ['caption' => 'Denied'])->assertForbidden();
        $this->deleteJson('/api/stories/'.$local->id)->assertForbidden();
        $role->forceFill(['permissions' => ['stories.moderation.view', 'stories.moderation.scope.all']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/admin/stories')->assertOk()->assertJsonCount(3, 'data');
        $this->patchJson('/api/admin/stories/'.$local->id.'/status', ['action' => 'hide'])->assertForbidden();
    }

    public function test_default_moderators_retain_scope_and_other_roles_are_denied(): void
    {
        $local = $this->story($this->actor('agent', 1, 10));
        $this->story($this->actor('agent', 1, 20));
        $this->story($this->actor('agent', 2, 30));
        foreach (['admin' => 3, 'superadmin' => 3, 'mop' => 1, 'branch_director' => 2] as $slug => $count) {
            $this->actor($slug);
            $this->getJson('/api/admin/stories')->assertOk()->assertJsonCount($count, 'data');
            $this->patchJson('/api/admin/stories/'.$local->id.'/status', ['action' => 'hide'])->assertOk();
        }
        foreach (array_diff(Role::pluck('slug')->all(), ['admin', 'superadmin', 'mop', 'branch_director']) as $slug) {
            $this->actor($slug);
            $this->getJson('/api/admin/stories')->assertForbidden();
        }
        $this->actor('mop', null, null);
        $this->getJson('/api/admin/stories')->assertForbidden();
    }

    public function test_editing_other_authors_requires_both_global_grant_and_action(): void
    {
        $story = $this->story($this->actor('agent'));
        $actor = $this->actor('admin');
        $this->patchJson('/api/stories/'.$story->id, ['caption' => 'Admin'])->assertOk();
        $actor->role->forceFill(['permissions' => ['stories.manage_all']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->patchJson('/api/stories/'.$story->id, ['caption' => 'Denied'])->assertForbidden();
        $this->deleteJson('/api/stories/'.$story->id)->assertForbidden();
        $actor->role->forceFill(['permissions' => ['stories.delete']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->deleteJson('/api/stories/'.$story->id)->assertForbidden();
        $actor->role->forceFill(['permissions' => ['stories.delete', 'stories.manage_all']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->deleteJson('/api/stories/'.$story->id)->assertOk();
    }
}
