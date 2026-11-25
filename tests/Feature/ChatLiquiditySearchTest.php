<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Services\Chat\PropertyRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatLiquiditySearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->travelTo(now()->startOfSecond());
        Schema::dropAllTables();
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Квартира');
            $table->string('offer_type')->default('sale');
            $table->unsignedBigInteger('type_id')->default(1);
            $table->unsignedBigInteger('location_id')->default(1);
            $table->unsignedBigInteger('status_id')->default(1);
            $table->integer('rooms')->default(2);
            $table->decimal('price')->default(500000);
            $table->decimal('discount_price')->nullable();
            $table->string('currency')->default('TJS');
            $table->string('address')->default('Рудаки');
            $table->string('district')->default('Сино');
            $table->string('listing_type')->default('regular');
            $table->string('moderation_status')->default('approved');
            $table->string('publication_status')->default('published');
            $table->string('deal_status')->default('available');
            $table->timestamp('sold_at')->nullable();
            $table->integer('liquidity_score')->nullable();
            $table->integer('liquidity_confidence')->nullable();
            $table->string('price_position')->nullable();
            $table->string('liquidity_model_version')->nullable();
            $table->timestamp('liquidity_calculated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('city');
        });
        DB::table('locations')->insert([['id' => 1, 'city' => 'Душанбе'], ['id' => 2, 'city' => 'Худжанд']]);
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        DB::table('property_types')->insert([
            ['id' => 1, 'name' => 'Квартира', 'slug' => 'apartment'],
            ['id' => 2, 'name' => 'Дом', 'slug' => 'house'],
        ]);
        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
        });
        DB::table('property_statuses')->insert([['id' => 1, 'slug' => 'available'], ['id' => 2, 'slug' => 'sold']]);
        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->integer('position');
        });
        Schema::create('property_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('type');
            $table->string('status');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
        });
        Schema::create('property_moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('status');
            $table->boolean('blocking');
        });
        Schema::create('property_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moderation_case_id');
            $table->string('decision');
            $table->timestamp('reversed_at')->nullable();
        });
    }

    public function test_liquidity_search_ranks_all_candidates_before_limiting_without_promotional_priority(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->promote($this->property(['liquidity_score' => 70, 'listing_type' => 'urgent']), 'urgent');
        }
        $vip = $this->property(['liquidity_score' => 80]);
        $this->promote($vip, 'vip');
        $regular = $this->property(['liquidity_score' => 90, 'created_at' => now()->subMonth()]);
        DB::table('property_photos')->insert(['property_id' => $regular, 'file_path' => 'photo.jpg', 'position' => 0]);

        $results = $this->search(['limit' => 2]);
        $this->assertSame([$regular, $vip], array_column($results, 'id'));
        $this->assertSame('regular', $results[0]['listing']);
        $this->assertSame('liquidity', $results[0]['selection']['basis']);
        $this->assertSame('Душанбе', $results[0]['city']);
        $this->assertSame('photo.jpg', $results[0]['image']);
        foreach (['liquidity_score', 'liquidity_confidence', 'price_delta_pct', 'market', 'owner_phone'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $results[0]);
        }
        $this->assertCount(6, $this->search(['limit' => 100]));
        $this->assertSame('urgent', app(PropertyRepository::class)->search(['limit' => 1])[0]['listing']);
    }

    public function test_liquidity_search_ignores_legacy_promotion_labels_too(): void
    {
        Schema::drop('property_promotions');
        $this->property(['liquidity_score' => 70, 'listing_type' => 'urgent']);
        $regular = $this->property(['liquidity_score' => 90]);
        $this->assertSame($regular, $this->search(['limit' => 1])[0]['id']);
    }

    public function test_only_current_reliable_and_public_sale_candidates_are_returned(): void
    {
        $eligible = $this->property(['liquidity_score' => 65, 'liquidity_confidence' => 45]);
        foreach ([
            ['liquidity_score' => 64], ['liquidity_score' => null],
            ['liquidity_confidence' => 44], ['liquidity_confidence' => null],
            ['liquidity_calculated_at' => now()->subHours(49)],
            ['liquidity_calculated_at' => null], ['liquidity_calculated_at' => now()->addDay()],
            ['liquidity_model_version' => 'obsolete'],
            ['price_position' => 'above_market'], ['price_position' => null],
            ['offer_type' => 'rent'], ['sold_at' => now()], ['moderation_status' => 'sold'],
            ['deal_status' => 'client_denied'], ['status_id' => 2],
            ['publication_status' => 'archived'], ['publication_status' => 'draft'],
        ] as $attributes) {
            $this->property($attributes);
        }
        foreach (['open', 'rejected'] as $status) {
            DB::table('property_moderation_cases')->insert([
                'property_id' => $this->property(), 'status' => $status, 'blocking' => true,
            ]);
        }
        $caseId = DB::table('property_moderation_cases')->insertGetId([
            'property_id' => $this->property(), 'status' => 'resolved', 'blocking' => false,
        ]);
        DB::table('property_duplicate_candidates')->insert([
            'moderation_case_id' => $caseId, 'decision' => 'confirmed_duplicate',
        ]);

        $this->assertSame([$eligible], array_column($this->search(), 'id'));
    }

    public static function nonMatchingFilters(): array
    {
        return [
            'city' => [['city' => 'Худжанд']],
            'district' => [['districts' => ['Фирдавси']]],
            'type' => [['property_type' => 'house']],
            'unknown type' => [['property_type' => 'missing-type']],
            'rooms' => [['rooms' => 3]],
            'minimum rooms' => [['rooms_min' => 3]],
            'maximum rooms' => [['rooms_max' => 1]],
            'budget' => [['price_max' => 400000]],
            'currency' => [['price_max' => 600000, 'currency' => 'USD']],
            'rental' => [['offer_type' => 'rent']],
        ];
    }

    #[DataProvider('nonMatchingFilters')]
    public function test_empty_liquidity_search_never_silently_drops_requested_filters(array $filters): void
    {
        $this->property();
        $this->assertSame([], $this->search($filters));
    }

    public function test_filters_include_discounted_prices_city_and_room_ranges(): void
    {
        $eligible = $this->property(['price' => 700000, 'discount_price' => 490000, 'rooms' => 3]);
        $this->property(['rooms' => 1]);
        $this->property(['location_id' => 2]);
        $results = $this->search([
            'city' => 'dushanbe', 'property_type' => 'квартира', 'districts' => ['Сино'],
            'rooms_min' => 2, 'rooms_max' => 3, 'price_min' => 480000, 'price_max' => 500000,
        ]);
        $this->assertSame([$eligible], array_column($results, 'id'));
        $this->assertSame(490000.0, $results[0]['price']);
    }

    public function test_missing_liquidity_data_does_not_fall_back_to_urgent_properties(): void
    {
        $this->property(['listing_type' => 'urgent', 'liquidity_score' => null]);
        $this->assertSame([], $this->search());
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('liquidity_score'));
        $this->assertSame([], $this->search());
        $this->assertCount(1, app(PropertyRepository::class)->search([]));
    }

    public function test_chat_endpoint_passes_liquidity_filters_and_returns_real_ranked_cards(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        foreach (['2025_10_01_000001_create_chat_sessions_table.php', '2025_10_01_000002_create_chat_messages_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        config(['services.openai.base_url' => 'https://provider.invalid', 'services.openai.api_key' => 'fixture-key']);
        $regular = $this->property(['rooms' => 3, 'price' => 490000]);
        $this->promote($this->property(['rooms' => 4]), 'urgent');
        $args = ['sort_by' => 'liquidity', 'city' => 'Душанбе', 'roomsFrom' => 2, 'roomsTo' => 3, 'priceTo' => 500000];
        $toolResponse = ['output' => [['type' => 'function_call', 'name' => 'search_properties', 'arguments' => json_encode($args)]]];
        $answer = ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Подборка по оценке платформы.']]]]];
        Http::fake(['*' => Http::sequence()->push($toolResponse)->push($answer)->push($toolResponse)->push($answer)]);

        $message = 'Покажи ликвидные квартиры в Душанбе, 2–3 комнаты до 500 тысяч';
        $reply = $this->postJson('/api/chat', ['message' => $message])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $regular)
            ->assertJsonPath('items.0.selection.basis', 'liquidity');
        $this->postJson('/api/chat', ['message' => 'Покажи ещё', 'session_id' => $reply['session_id']])->assertOk()
            ->assertJsonPath('items.0.id', $regular);

        $requests = Http::recorded();
        $schema = $requests[0][0]['tools'][0]['parameters']['properties'];
        $this->assertSame(['recommended', 'liquidity'], $schema['sort_by']['enum']);
        $this->assertArrayHasKey('city', $schema);
        $this->assertArrayNotHasKey('listing_type', $schema);
        $envelope = $requests[1][0]['input'][2]['content'];
        $this->assertStringContainsString('"basis":"liquidity"', $envelope);
        $this->assertStringNotContainsString('liquidity_score', $envelope);
        $this->assertContains($message, array_column($requests[2][0]['input'], 'content'));
        $tool = ChatMessage::where('role', 'tool')->firstOrFail();
        $this->assertSame(2, $tool->tool_args['rooms_min']);
        $this->assertSame(3, $tool->tool_args['rooms_max']);
        $this->assertSame('liquidity', $tool->tool_args['sort_by']);
        Http::assertSentCount(4);
    }

    private function property(array $attributes = []): int
    {
        return DB::table('properties')->insertGetId(array_replace([
            'liquidity_score' => 85, 'liquidity_confidence' => 70, 'price_position' => 'at_market',
            'liquidity_model_version' => config('property-liquidity.model_version'),
            'liquidity_calculated_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function promote(int $id, string $type): void
    {
        DB::table('property_promotions')->insert([
            'property_id' => $id, 'type' => $type, 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
    }

    private function search(array $args = []): array
    {
        return app(PropertyRepository::class)->search(['sort_by' => 'liquidity', ...$args]);
    }
}
