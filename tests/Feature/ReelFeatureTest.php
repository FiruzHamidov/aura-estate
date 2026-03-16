<?php

namespace Tests\Feature;

use App\Jobs\ProcessReelVideo;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Reel;
use App\Models\Role;
use App\Models\User;
use App\Services\Reels\ReelThumbnailGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReelFeatureTest extends TestCase
{
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->float('total_area')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('moderation_status')->default('approved');
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

        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('video_url');
            $table->string('hls_url')->nullable();
            $table->string('mp4_url')->nullable();
            $table->string('preview_image')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('aspect_ratio', 16)->default('9:16');
            $table->string('status', 32)->default(Reel::STATUS_DRAFT);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('video_size')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->string('transcode_status', 32)->default(Reel::TRANSCODE_PENDING);
            $table->json('processing_meta')->nullable();
            $table->unsignedInteger('poster_second')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('reel_likes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reel_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('guest_token', 100)->nullable();
            $table->timestamps();

            $table->unique(['reel_id', 'user_id']);
            $table->unique(['reel_id', 'guest_token']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_agent_can_create_standalone_reel_and_queue_processing(): void
    {
        Storage::fake('public');
        Queue::fake();

        $agent = $this->createUser('agent', '930100001');
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/reels', [
            'title' => 'Standalone reel',
            'video' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'Standalone reel');
        $response->assertJsonPath('property_id', null);
        $response->assertJsonPath('status', Reel::STATUS_PROCESSING);
        $response->assertJsonPath('transcode_status', Reel::TRANSCODE_QUEUED);

        $this->assertDatabaseCount('reels', 1);
        Queue::assertPushed(ProcessReelVideo::class);
    }

    public function test_agent_can_create_reel_with_duration_up_to_five_minutes(): void
    {
        Storage::fake('public');
        Queue::fake();

        $agent = $this->createUser('agent', '9301000015');
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/reels', [
            'title' => 'Long reel',
            'duration' => 300,
            'poster_second' => 300,
            'video' => UploadedFile::fake()->create('long-clip.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('duration', 300);

        $this->assertDatabaseHas('reels', [
            'title' => 'Long reel',
            'duration' => 300,
            'poster_second' => 300,
        ]);
    }

    public function test_agent_can_initialize_direct_upload_for_standalone_reel(): void
    {
        $agent = $this->createUser('agent', '930100012');
        Sanctum::actingAs($agent);

        $service = Mockery::mock(\App\Services\Reels\ReelUploadService::class);
        $service->shouldReceive('diskName')->andReturn('s3');
        $service->shouldReceive('createTemporaryUpload')
            ->once()
            ->andReturn([
                'disk' => 's3',
                'path' => 'reels/originals/2026/03/test.mp4',
                'method' => 'PUT',
                'upload_url' => 'https://uploads.example.test/reels/originals/2026/03/test.mp4',
                'headers' => ['Content-Type' => 'video/mp4'],
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ]);
        $service->shouldReceive('directUploadPath')->once()->andReturn('reels/originals/2026/03/test.mp4');
        $service->shouldReceive('publicUrl')->andReturn(null);
        $this->app->instance(\App\Services\Reels\ReelUploadService::class, $service);

        $response = $this->postJson('/api/reels/direct-upload', [
            'title' => 'Direct reel',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size' => 4096,
            'original_name' => 'direct.mp4',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('reel.title', 'Direct reel');
        $response->assertJsonPath('reel.status', Reel::STATUS_UPLOADING);
        $response->assertJsonPath('upload.method', 'PUT');
        $response->assertJsonPath('upload.disk', 's3');
    }

    public function test_agent_can_complete_direct_upload_and_queue_processing(): void
    {
        Queue::fake();

        $agent = $this->createUser('agent', '930100013');
        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Upload pending',
            'video_url' => 'reels/originals/pending.mp4',
            'status' => Reel::STATUS_UPLOADING,
            'mime_type' => 'video/mp4',
            'processing_meta' => [
                'upload' => [
                    'mode' => 'direct',
                    'status' => 'initialized',
                ],
            ],
        ]);

        Sanctum::actingAs($agent);

        $service = Mockery::mock(\App\Services\Reels\ReelUploadService::class);
        $service->shouldReceive('fileExists')->with('reels/originals/pending.mp4')->andReturn(true);
        $service->shouldReceive('fileSize')->with('reels/originals/pending.mp4')->andReturn(5000);
        $service->shouldReceive('publicUrl')->andReturn(null);
        $this->app->instance(\App\Services\Reels\ReelUploadService::class, $service);

        $response = $this->postJson('/api/reels/'.$reel->id.'/complete-upload');

        $response->assertOk();
        $response->assertJsonPath('status', Reel::STATUS_PROCESSING);
        $response->assertJsonPath('transcode_status', Reel::TRANSCODE_QUEUED);

        Queue::assertPushed(ProcessReelVideo::class);
    }

    public function test_process_job_generates_preview_and_thumbnail_when_missing(): void
    {
        Storage::fake('public');

        $agent = $this->createUser('agent', '9301000131');
        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Needs preview',
            'video_url' => 'reels/originals/generated.mp4',
            'status' => Reel::STATUS_PROCESSING,
            'mime_type' => 'video/mp4',
            'transcode_status' => Reel::TRANSCODE_QUEUED,
            'processing_meta' => [
                'queued_at' => now()->toIso8601String(),
            ],
        ]);

        $generator = Mockery::mock(ReelThumbnailGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn (Reel $jobReel) => $jobReel->is($reel)))
            ->andReturn([
                'preview_image' => 'reels/previews/2026/03/generated-preview.jpg',
                'thumbnail_url' => 'reels/thumbnails/2026/03/generated-thumb.jpg',
            ]);

        $this->app->instance(ReelThumbnailGenerator::class, $generator);

        app(ProcessReelVideo::class, ['reelId' => $reel->id])->handle($generator);

        $processed = $reel->fresh();

        $this->assertSame(Reel::TRANSCODE_COMPLETED, $processed->transcode_status);
        $this->assertSame(Reel::STATUS_PUBLISHED, $processed->status);
        $this->assertSame('reels/previews/2026/03/generated-preview.jpg', $processed->preview_image);
        $this->assertSame('reels/thumbnails/2026/03/generated-thumb.jpg', $processed->thumbnail_url);
        $this->assertSame('generated', $processed->processing_meta['preview_generation']['status']);
        $this->assertSame('ffmpeg', $processed->processing_meta['pipeline']);
    }

    public function test_authenticated_user_can_like_and_unlike_published_reel(): void
    {
        $agent = $this->createUser('agent', '930100014');
        $viewer = $this->createUser('client', '930100016');

        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Popular reel',
            'video_url' => 'reels/originals/popular.mp4',
            'mp4_url' => 'reels/originals/popular.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson('/api/reels/'.$reel->id.'/like')
            ->assertCreated()
            ->assertJsonPath('likes_count', 1)
            ->assertJsonPath('is_liked', true);

        $this->assertDatabaseHas('reel_likes', [
            'reel_id' => $reel->id,
            'user_id' => $viewer->id,
        ]);
        $this->assertSame(1, $reel->fresh()->likes_count);

        $this->getJson('/api/reels/'.$reel->id.'/like-status')
            ->assertOk()
            ->assertJsonPath('likes_count', 1)
            ->assertJsonPath('is_liked', true);

        $this->getJson('/api/reels/'.$reel->id)
            ->assertOk()
            ->assertJsonPath('is_liked', true)
            ->assertJsonPath('likes_count', 1);

        $this->deleteJson('/api/reels/'.$reel->id.'/like')
            ->assertOk()
            ->assertJsonPath('likes_count', 0)
            ->assertJsonPath('is_liked', false);

        $this->assertDatabaseMissing('reel_likes', [
            'reel_id' => $reel->id,
            'user_id' => $viewer->id,
        ]);
        $this->assertSame(0, $reel->fresh()->likes_count);
    }

    public function test_user_cannot_like_unpublished_reel(): void
    {
        $agent = $this->createUser('agent', '930100017');
        $viewer = $this->createUser('client', '930100018');

        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Draft reel',
            'video_url' => 'reels/originals/draft-like.mp4',
            'status' => Reel::STATUS_DRAFT,
            'transcode_status' => Reel::TRANSCODE_PENDING,
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson('/api/reels/'.$reel->id.'/like')
            ->assertNotFound();

        $this->assertDatabaseCount('reel_likes', 0);
    }

    public function test_guest_can_like_and_unlike_published_reel_with_guest_token(): void
    {
        $agent = $this->createUser('agent', '930100019');

        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Guest reel',
            'video_url' => 'reels/originals/guest.mp4',
            'mp4_url' => 'reels/originals/guest.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        $guestToken = 'guest-device-token-1';

        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/reels/'.$reel->id.'/like')
            ->assertCreated()
            ->assertJsonPath('likes_count', 1)
            ->assertJsonPath('is_liked', true);

        $this->assertDatabaseHas('reel_likes', [
            'reel_id' => $reel->id,
            'guest_token' => $guestToken,
        ]);

        $this->withHeader('X-Guest-Token', $guestToken)
            ->getJson('/api/reels/'.$reel->id.'/like-status')
            ->assertOk()
            ->assertJsonPath('likes_count', 1)
            ->assertJsonPath('is_liked', true);

        $this->withHeader('X-Guest-Token', $guestToken)
            ->getJson('/api/reels/'.$reel->id)
            ->assertOk()
            ->assertJsonPath('is_liked', true)
            ->assertJsonPath('likes_count', 1);

        $this->withHeader('X-Guest-Token', $guestToken)
            ->deleteJson('/api/reels/'.$reel->id.'/like')
            ->assertOk()
            ->assertJsonPath('likes_count', 0)
            ->assertJsonPath('is_liked', false);
    }

    public function test_guest_like_requires_guest_token(): void
    {
        $agent = $this->createUser('agent', '930100020');

        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Token required',
            'video_url' => 'reels/originals/token-required.mp4',
            'mp4_url' => 'reels/originals/token-required.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        $this->postJson('/api/reels/'.$reel->id.'/like')
            ->assertStatus(422);
    }

    public function test_public_reels_feed_returns_published_property_and_standalone_reels_only(): void
    {
        $agent = $this->createUser('agent', '930100002');
        $property = $this->createProperty($agent, 'Property Reel');

        $linked = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Linked',
            'video_url' => 'reels/originals/linked.mp4',
            'mp4_url' => 'reels/originals/linked.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        $standalone = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Standalone',
            'video_url' => 'reels/originals/standalone.mp4',
            'mp4_url' => 'reels/originals/standalone.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
            'sort_order' => 1,
        ]);

        Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Draft',
            'video_url' => 'reels/originals/draft.mp4',
            'status' => Reel::STATUS_DRAFT,
            'transcode_status' => Reel::TRANSCODE_PENDING,
        ]);

        $response = $this->getJson('/api/reels');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $linked->id]);
        $response->assertJsonFragment(['id' => $standalone->id]);
        $response->assertJsonMissing(['title' => 'Draft']);
    }

    public function test_property_show_includes_only_published_reels(): void
    {
        $agent = $this->createUser('agent', '930100003');
        $property = $this->createProperty($agent, 'Property with reels');

        $published = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Published Reel',
            'video_url' => 'reels/originals/published.mp4',
            'mp4_url' => 'reels/originals/published.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Draft Reel',
            'video_url' => 'reels/originals/draft.mp4',
            'status' => Reel::STATUS_DRAFT,
            'transcode_status' => Reel::TRANSCODE_PENDING,
        ]);

        $response = $this->getJson('/api/properties/'.$property->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'reels');
        $response->assertJsonPath('reels.0.id', $published->id);
        $response->assertJsonMissing(['title' => 'Draft Reel']);
    }

    public function test_property_reels_endpoint_can_include_unpublished_for_owner(): void
    {
        $agent = $this->createUser('agent', '930100004');
        $property = $this->createProperty($agent, 'Managed property');

        $published = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Published',
            'video_url' => 'reels/originals/published.mp4',
            'mp4_url' => 'reels/originals/published.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        $draft = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Draft',
            'video_url' => 'reels/originals/draft.mp4',
            'status' => Reel::STATUS_DRAFT,
            'transcode_status' => Reel::TRANSCODE_PENDING,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/properties/'.$property->id.'/reels?include_unpublished=1');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => $published->id]);
        $response->assertJsonFragment(['id' => $draft->id]);
    }

    public function test_property_reels_endpoint_accepts_true_string_for_include_unpublished(): void
    {
        $agent = $this->createUser('agent', '930100004');
        $property = $this->createProperty($agent, 'Managed property');

        $published = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Published',
            'video_url' => 'reels/originals/published.mp4',
            'mp4_url' => 'reels/originals/published.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        $draft = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Draft',
            'video_url' => 'reels/originals/draft.mp4',
            'status' => Reel::STATUS_DRAFT,
            'transcode_status' => Reel::TRANSCODE_PENDING,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/properties/'.$property->id.'/reels?include_unpublished=true');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => $published->id]);
        $response->assertJsonFragment(['id' => $draft->id]);
    }

    public function test_deleting_property_archives_related_reels(): void
    {
        $agent = $this->createUser('agent', '930100005');
        $property = $this->createProperty($agent, 'Delete me');

        $reel = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Published Reel',
            'video_url' => 'reels/originals/published.mp4',
            'mp4_url' => 'reels/originals/published.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->deleteJson('/api/properties/'.$property->id)->assertOk();

        $this->assertDatabaseHas('reels', [
            'id' => $reel->id,
            'status' => Reel::STATUS_ARCHIVED,
        ]);
        $this->assertNull($reel->fresh()->published_at);
    }

    public function test_destroy_deletes_reel_media_files(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('reels/originals/delete-me.mp4', 'video');
        Storage::disk('public')->put('reels/previews/delete-me.jpg', 'preview');
        Storage::disk('public')->put('reels/thumbnails/delete-me.jpg', 'thumb');

        $agent = $this->createUser('agent', '9301000051');
        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Delete files',
            'video_url' => 'reels/originals/delete-me.mp4',
            'mp4_url' => 'reels/originals/delete-me.mp4',
            'preview_image' => 'reels/previews/delete-me.jpg',
            'thumbnail_url' => 'reels/thumbnails/delete-me.jpg',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->deleteJson('/api/reels/'.$reel->id)->assertOk();

        Storage::disk('public')->assertMissing('reels/originals/delete-me.mp4');
        Storage::disk('public')->assertMissing('reels/previews/delete-me.jpg');
        Storage::disk('public')->assertMissing('reels/thumbnails/delete-me.jpg');
    }

    public function test_update_preview_replaces_old_preview_file(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('reels/originals/keep-video.mp4', 'video');
        Storage::disk('public')->put('reels/previews/old-preview.jpg', 'old-preview');

        $agent = $this->createUser('agent', '9301000052');
        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Replace preview',
            'video_url' => 'reels/originals/keep-video.mp4',
            'preview_image' => 'reels/previews/old-preview.jpg',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $response = $this->patch('/api/reels/'.$reel->id, [
            'preview_image' => UploadedFile::fake()->image('new-preview.jpg'),
        ]);

        $response->assertOk();
        $newPreview = $response->json('preview_image');

        $this->assertNotSame('reels/previews/old-preview.jpg', $newPreview);
        Storage::disk('public')->assertMissing('reels/previews/old-preview.jpg');
        Storage::disk('public')->assertExists($newPreview);
    }

    public function test_update_video_replaces_old_media_and_requeues_processing(): void
    {
        Storage::fake('public');
        Queue::fake();

        Storage::disk('public')->put('reels/originals/old-video.mp4', 'old-video');
        Storage::disk('public')->put('reels/previews/old-preview.jpg', 'old-preview');
        Storage::disk('public')->put('reels/thumbnails/old-thumb.jpg', 'old-thumb');

        $agent = $this->createUser('agent', '9301000053');
        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Replace video',
            'video_url' => 'reels/originals/old-video.mp4',
            'mp4_url' => 'reels/originals/old-video.mp4',
            'preview_image' => 'reels/previews/old-preview.jpg',
            'thumbnail_url' => 'reels/thumbnails/old-thumb.jpg',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
            'processing_meta' => [
                'processed_at' => now()->toIso8601String(),
                'preview_generation' => ['status' => 'generated'],
            ],
        ]);

        Sanctum::actingAs($agent);

        $response = $this->patch('/api/reels/'.$reel->id, [
            'video' => UploadedFile::fake()->create('replacement.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertOk();
        $updated = $reel->fresh();

        $this->assertNotSame('reels/originals/old-video.mp4', $updated->video_url);
        $this->assertNull($updated->preview_image);
        $this->assertNull($updated->thumbnail_url);
        $this->assertSame(Reel::STATUS_PROCESSING, $updated->status);
        $this->assertSame(Reel::TRANSCODE_QUEUED, $updated->transcode_status);
        $this->assertNull($updated->published_at);
        $this->assertArrayNotHasKey('processed_at', $updated->processing_meta);
        $this->assertArrayNotHasKey('preview_generation', $updated->processing_meta);

        Storage::disk('public')->assertMissing('reels/originals/old-video.mp4');
        Storage::disk('public')->assertMissing('reels/previews/old-preview.jpg');
        Storage::disk('public')->assertMissing('reels/thumbnails/old-thumb.jpg');
        Storage::disk('public')->assertExists($updated->video_url);

        Queue::assertPushed(ProcessReelVideo::class);
    }

    public function test_rop_can_create_reel_for_agent_property_from_same_branch(): void
    {
        Storage::fake('public');
        Queue::fake();

        $rop = $this->createUser('rop', '930100006', 10);
        $agent = $this->createUser('agent', '930100007', 10);
        $property = $this->createProperty($agent, 'Branch property');

        Sanctum::actingAs($rop);

        $response = $this->postJson('/api/reels', [
            'property_id' => $property->id,
            'title' => 'ROP reel',
            'video' => UploadedFile::fake()->create('branch.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('property_id', $property->id);
        $response->assertJsonPath('created_by', $rop->id);
    }

    public function test_branch_director_can_manage_standalone_reel_of_agent_from_same_branch(): void
    {
        $director = $this->createUser('branch_director', '930100008', 20);
        $agent = $this->createUser('agent', '930100009', 20);

        $reel = Reel::create([
            'created_by' => $agent->id,
            'title' => 'Agent standalone',
            'video_url' => 'reels/originals/agent.mp4',
            'mp4_url' => 'reels/originals/agent.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($director);

        $this->patchJson('/api/reels/'.$reel->id, [
            'title' => 'Updated by director',
        ])->assertOk()
            ->assertJsonPath('title', 'Updated by director');
    }

    public function test_rop_cannot_manage_reel_of_agent_from_other_branch(): void
    {
        $rop = $this->createUser('rop', '930100010', 30);
        $agent = $this->createUser('agent', '930100011', 31);
        $property = $this->createProperty($agent, 'Foreign branch property');

        $reel = Reel::create([
            'property_id' => $property->id,
            'created_by' => $agent->id,
            'title' => 'Foreign reel',
            'video_url' => 'reels/originals/foreign.mp4',
            'mp4_url' => 'reels/originals/foreign.mp4',
            'status' => Reel::STATUS_PUBLISHED,
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($rop);

        $this->patchJson('/api/reels/'.$reel->id, [
            'title' => 'Blocked update',
        ])->assertForbidden();
    }

    private function createUser(string $roleSlug, string $phone, ?int $branchId = null): User
    {
        $role = Role::create([
            'name' => ucfirst($roleSlug),
            'slug' => $roleSlug,
        ]);

        return User::create([
            'name' => ucfirst($roleSlug).' User',
            'phone' => $phone,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function createProperty(User $user, string $title): Property
    {
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);

        return Property::create([
            'title' => $title,
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $user->id,
            'agent_id' => $user->id,
            'moderation_status' => 'approved',
        ]);
    }
}
