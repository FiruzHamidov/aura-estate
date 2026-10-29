<?php

namespace Tests\Feature;

use App\Services\Chat\ChatService;
use App\Services\Chat\PropertyRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ChatProviderConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['services.openai' => [
            'base_url' => 'https://provider.invalid',
            'model' => 'fixture-model',
            'api_key' => 'fixture-api-key',
            'relay_shared_key' => 'fixture-relay-key',
        ]]);
        Schema::dropAllTables();
        Schema::create('users', fn (Blueprint $table) => $table->id());
        foreach (['2025_10_01_000001_create_chat_sessions_table.php', '2025_10_01_000002_create_chat_messages_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    public static function authenticationModes(): array
    {
        return ['relay' => [true], 'direct' => [false]];
    }

    #[DataProvider('authenticationModes')]
    public function test_chat_and_diagnostic_command_use_cached_settings_without_environment_variables(bool $relay): void
    {
        $directory = sys_get_temp_dir().'/aura-chat-config-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'APP_SERVICES_CACHE' => $directory.'/services.php',
            'APP_PACKAGES_CACHE' => $directory.'/packages.php',
            'APP_ROUTES_CACHE' => $directory.'/routes.php',
            'APP_EVENTS_CACHE' => $directory.'/events.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'LOG_CHANNEL' => 'null', 'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'OPENAI_BASE' => false, 'OPENAI_MODEL' => false,
            'OPENAI_API_KEY' => false, 'RELAY_SHARED_KEY' => false,
        ];
        try {
            $cached = $this->probe('cache', array_replace($environment, [
                'OPENAI_BASE' => 'https://cached-provider.invalid',
                'OPENAI_MODEL' => 'cached-fixture-model',
                'OPENAI_API_KEY' => 'fixture-api-key',
                'RELAY_SHARED_KEY' => $relay ? 'fixture-relay-key' : '',
            ]));
            $this->assertSame(['exit' => 0, 'cached' => true], $cached);

            // A fresh PHP process cannot read the original dotenv values once cached.
            $result = $this->probe('request', $environment);
            $this->assertTrue($result['cached']);
            $this->assertFalse($result['runtime_env_present']);
            $this->assertSame('Fixture answer', $result['answer']);
            $this->assertSame(0, $result['command_exit']);
            $this->assertCount(2, $result['requests']);
            foreach ($result['requests'] as $request) {
                $this->assertSame('https://cached-provider.invalid/v1/responses', $request['url']);
                $this->assertSame('cached-fixture-model', $request['model']);
                $this->assertSame($relay, $request['relay_auth']);
                $this->assertSame(! $relay, $request['bearer_auth']);
            }
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_both_search_requests_use_configured_model_and_relay(): void
    {
        $this->mock(PropertyRepository::class)->shouldReceive('search')->once()->andReturn([]);
        Http::fake(['*' => Http::sequence()->push([
            'output' => [['type' => 'function_call', 'name' => 'search_properties', 'arguments' => '{}']],
        ])->push($this->answer())]);

        $reply = app(ChatService::class)->reply('Fixture search', null, null);

        $this->assertSame('Fixture answer', $reply['answer']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('https://provider.invalid/v1/responses', $request->url());
            $this->assertSame('fixture-model', $request['model']);
            $this->assertTrue($request->hasHeader('X-Relay-Key', 'fixture-relay-key'));
            $this->assertFalse($request->hasHeader('Authorization'));
        }
    }

    public function test_missing_credentials_do_not_send_anonymous_requests(): void
    {
        config(['services.openai.api_key' => null, 'services.openai.relay_shared_key' => null]);
        Http::fake();

        $reply = app(ChatService::class)->reply('Fixture greeting', null, null);

        $this->assertSame('Сервис ИИ сейчас недоступен. Попробуйте позже, пожалуйста.', $reply['answer']);
        $this->assertSame(1, Artisan::call('openai:test'));
        $this->assertStringContainsString('credentials are not configured', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_provider_errors_are_not_reported_as_a_successful_diagnostic(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Private provider details']], 403)]);

        $reply = app(ChatService::class)->reply('Fixture greeting', null, null);

        $this->assertSame('Сервис ИИ сейчас недоступен. Попробуйте позже, пожалуйста.', $reply['answer']);
        $this->assertSame(1, Artisan::call('openai:test'));
        $this->assertStringContainsString('HTTP 403', Artisan::output());
        $this->assertStringNotContainsString('Private provider details', Artisan::output());
        Http::assertSentCount(2);
    }

    private function answer(): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Fixture answer']]]]];
    }

    private function probe(string $mode, array $environment): array
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/chat_config_cache_probe.php'), $mode], base_path(), $environment);
        $process->setTimeout(30)->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
