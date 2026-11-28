<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Services\Chat\PropertyRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ChatPropertyDatabase;
use Tests\TestCase;

class ChatRepairSearchTest extends TestCase
{
    use ChatPropertyDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createChatPropertyDatabase();
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->string('renovation_permission_status')->nullable();
            $table->decimal('total_area')->nullable();
        });
        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        // IDs deliberately differ from production; classification uses the catalog names.
        DB::table('repair_types')->insert([
            ['id' => 17, 'name' => 'Коробка'],
            ['id' => 23, 'name' => 'Без ремонта / коробка'],
            ['id' => 31, 'name' => 'Евроремонт'],
            ['id' => 42, 'name' => 'Новый ремонт'],
            ['id' => 59, 'name' => 'Не указано'],
            ['id' => 63, 'name' => 'Требует ремонта'],
        ]);
    }

    public function test_shell_and_permission_are_separate_strict_filters(): void
    {
        $allowed = $this->property();
        $otherShell = $this->property(['repair_type_id' => 23]);
        $notAllowed = $this->property(['renovation_permission_status' => 'not_allowed']);
        $unknown = $this->property(['renovation_permission_status' => null]);
        foreach ([31, 42, 59, 63, null] as $repair) {
            $this->property(['repair_type_id' => $repair]);
        }
        $this->assertSame([$otherShell, $allowed], $this->ids($this->search()));
        $this->assertSame([$unknown, $notAllowed, $otherShell, $allowed], $this->ids($this->search(['renovation_permission_status' => 'any'])));
        $this->assertSame([$notAllowed], $this->ids($this->search(['renovation_permission_status' => 'not_allowed'])));
        $result = $this->search()[0];
        $this->assertSame('shell', $result['repair_condition']);
        $this->assertSame('Без ремонта / коробка', $result['repair_type']['name']);
        $this->assertSame('allowed', $result['renovation_permission_status']);
        $this->assertSame(50.0, $result['area']);
    }

    public function test_smallest_search_sorts_actual_area_before_limit_and_promotions(): void
    {
        $expected = [];
        for ($area = 20; $area <= 31; $area++) {
            $expected[] = $this->property(['total_area' => $area]);
        }
        $large = $this->property(['total_area' => 100, 'listing_type' => 'urgent']);
        DB::table('property_promotions')->insert([
            'property_id' => $large, 'type' => 'urgent', 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        foreach ([null, 0, -1] as $area) {
            $this->property(['total_area' => $area]);
        }
        $this->property(['total_area' => 10, 'repair_type_id' => 31]);
        $this->property(['total_area' => 15, 'renovation_permission_status' => null]);
        $results = $this->search(['sort_by' => 'area_asc', 'limit' => 100]);
        $this->assertSame(array_slice($expected, 0, 10), $this->ids($results));
        $this->assertSame(array_map(fn ($area) => (float) $area, range(20, 29)), array_column($results, 'area'));
        $this->assertCount(6, $this->search(['sort_by' => 'area_asc']));
        $this->assertSame(array_slice($expected, 2, 3), $this->ids($this->search(['sort_by' => 'area_asc', 'areaFrom' => 22, 'areaTo' => 24])));
    }

    public static function mismatches(): array
    {
        return [
            'city' => [['city' => 'Худжанд']],
            'type' => [['property_type' => 'house']],
            'unknown type' => [['property_type' => 'unrecognized']],
            'rooms' => [['rooms_min' => 3]],
            'district' => [['districts' => ['Фирдавси']]],
            'price' => [['price_max' => 400000]],
            'area' => [['areaTo' => 40]],
            'rent' => [['offer_type' => 'rent']],
            'invalid condition' => [['repair_condition' => 'invalid']],
            'invalid permission' => [['renovation_permission_status' => 'invalid']],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_no_exact_match_does_not_relax_other_requirements(array $args): void
    {
        $this->property();
        $this->assertSame([], $this->search($args));
    }

    public function test_repair_filter_also_applies_to_liquidity_search(): void
    {
        $shell = $this->property(['liquidity_score' => 70]);
        $this->property(['repair_type_id' => 31, 'liquidity_score' => 99]);
        $this->assertSame([$shell], $this->ids($this->search(['sort_by' => 'liquidity'])));
    }

    public function test_generic_apartments_cover_new_buildings_and_resale_but_not_houses(): void
    {
        DB::table('property_types')->where('id', 1)->update(['name' => 'Вторичка', 'slug' => 'resale']);
        DB::table('property_types')->insert(['id' => 3, 'name' => 'Новостройки', 'slug' => 'new-buildings']);
        $resale = $this->property();
        $new = $this->property(['type_id' => 3]);
        $this->property(['type_id' => 2]);
        $this->assertSame([$new, $resale], $this->ids($this->search(['property_type' => 'квартира'])));
        $this->assertSame([$new], $this->ids($this->search(['property_type' => 'new-buildings'])));
    }

    public function test_unavailable_catalog_or_permission_data_never_broadens_results(): void
    {
        $this->property(['repair_type_id' => 31]);
        $this->assertSame([], $this->search());
        DB::table('repair_types')->whereIn('id', [17, 23])->delete();
        $this->assertSame([], $this->search());
        Schema::drop('repair_types');
        $this->assertSame([], $this->search());
        $this->assertCount(1, app(PropertyRepository::class)->search([]));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('renovation_permission_status'));
        $this->assertSame([], app(PropertyRepository::class)->search(['renovation_permission_status' => 'allowed']));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('total_area'));
        $this->assertSame([], app(PropertyRepository::class)->search(['sort_by' => 'area_asc']));
    }

    public function test_customer_transliterated_conversation_keeps_constraints_when_tool_omits_them(): void
    {
        $this->createChatTables();
        $small = $this->property(['total_area' => 30]);
        $large = $this->property(['total_area' => 65]);
        $renovated = $this->property(['repair_type_id' => 31, 'total_area' => 25]);
        $this->property(['renovation_permission_status' => null, 'total_area' => 20]);
        $args = ['property_type' => 'apartment', 'districts' => ['Сино'], 'offer_type' => 'sale', 'sort_by' => 'area_asc', 'limit' => 10];
        Http::fake(['*' => Http::sequence()
            ->push($this->answer('Продажа или аренда?'))
            ->push($this->answer('Ищем в Сино.'))
            ->push($this->tool($args))->push($this->answer('Подборка.'))
            ->push($this->tool($args))->push($this->answer('С ремонтом.'))]);

        $reply = $this->postJson('/api/chat', ['message' => 'podberi 10 samix malenkix kvartir korobok s razrisheniyami na remonta'])->assertOk();
        $session = $reply['session_id'];
        $this->postJson('/api/chat', ['message' => 'prodaju', 'session_id' => $session])->assertOk();
        $this->postJson('/api/chat', ['message' => 'davay', 'session_id' => $session])->assertOk()
            ->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', $small)->assertJsonPath('items.1.id', $large)
            ->assertJsonPath('items.0.area', 30)->assertJsonPath('items.0.repair_condition', 'shell')
            ->assertJsonPath('items.0.renovation_permission_status', 'allowed');
        $tool = ChatMessage::where('role', 'tool')->firstOrFail();
        $this->assertSame('shell', $tool->tool_args['repair_condition']);
        $this->assertSame('allowed', $tool->tool_args['renovation_permission_status']);
        $requests = Http::recorded();
        $schema = $requests[0][0]['tools'][0]['parameters']['properties'];
        $this->assertSame(['shell', 'renovated', 'any'], $schema['repair_condition']['enum']);
        $this->assertSame(['allowed', 'not_allowed', 'any'], $schema['renovation_permission_status']['enum']);
        $this->assertSame(10, $schema['limit']['maximum']);
        $input = $requests[3][0]['input'];
        $envelope = end($input)['content'];
        $this->assertStringContainsString('"area":30', $envelope);
        $this->assertStringContainsString('"repair_condition":"shell"', $envelope);

        $this->postJson('/api/chat', ['message' => 'Теперь с ремонтом', 'session_id' => $session])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $renovated);
        Http::assertSentCount(6);
    }

    public function test_empty_chat_search_does_not_send_renovated_cards_to_the_model(): void
    {
        $this->createChatTables();
        $this->property(['repair_type_id' => 31]);
        Http::fake(['*' => Http::sequence()->push($this->tool([]))->push($this->answer('Точных совпадений нет.'))]);
        $this->postJson('/api/chat', ['message' => 'Покажи коробки с разрешением на ремонт'])->assertOk()->assertJsonCount(0, 'items');
        $requests = Http::recorded();
        $input = $requests[1][0]['input'];
        $this->assertStringContainsString('"results":[]', end($input)['content']);
    }

    private function createChatTables(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        foreach (['2025_10_01_000001_create_chat_sessions_table.php', '2025_10_01_000002_create_chat_messages_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        config(['services.openai.base_url' => 'https://provider.invalid', 'services.openai.api_key' => 'fixture-key']);
    }

    private function property(array $attributes = []): int
    {
        return DB::table('properties')->insertGetId(array_replace([
            'repair_type_id' => 17, 'renovation_permission_status' => 'allowed', 'total_area' => 50,
            'liquidity_score' => 85, 'liquidity_confidence' => 70, 'price_position' => 'at_market',
            'liquidity_model_version' => config('property-liquidity.model_version'),
            'liquidity_calculated_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function search(array $args = []): array
    {
        return app(PropertyRepository::class)->search(array_replace([
            'repair_condition' => 'shell', 'renovation_permission_status' => 'allowed',
        ], $args));
    }

    private function ids(array $results): array
    {
        return array_column($results, 'id');
    }

    private function tool(array $args): array
    {
        return ['output' => [['type' => 'function_call', 'name' => 'search_properties', 'arguments' => json_encode($args)]]];
    }

    private function answer(string $text): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
    }
}
