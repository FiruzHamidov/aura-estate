<?php

// Run only from ChatProviderConfigurationTest with a private, disposable cache.
use App\Services\Chat\ChatService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

umask(0077);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:'
    || ! str_starts_with($app->getCachedConfigPath(), sys_get_temp_dir().'/aura-chat-config-')) {
    throw new RuntimeException('This probe requires an isolated test environment.');
}

Http::preventStrayRequests();
if (($argv[1] ?? '') === 'cache') {
    $exit = Artisan::call('config:cache');
    echo json_encode(['exit' => $exit, 'cached' => $app->configurationIsCached()]);
    exit($exit);
}

Http::fake(['*' => Http::response([
    'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Fixture answer']]]],
])]);
Schema::create('users', fn (Blueprint $table) => $table->id());
foreach (['2025_10_01_000001_create_chat_sessions_table.php', '2025_10_01_000002_create_chat_messages_table.php'] as $file) {
    (require database_path('migrations/'.$file))->up();
}
$reply = app(ChatService::class)->reply('Fixture greeting', null, null);
$commandExit = Artisan::call('openai:test');

echo json_encode([
    'cached' => $app->configurationIsCached(),
    'runtime_env_present' => (bool) (env('OPENAI_BASE') || env('OPENAI_MODEL') || env('OPENAI_API_KEY') || env('RELAY_SHARED_KEY')),
    'answer' => $reply['answer'],
    'command_exit' => $commandExit,
    'requests' => Http::recorded()->map(fn ($pair) => [
        'url' => $pair[0]->url(),
        'model' => $pair[0]['model'],
        'relay_auth' => $pair[0]->hasHeader('X-Relay-Key', 'fixture-relay-key'),
        'bearer_auth' => $pair[0]->hasHeader('Authorization', 'Bearer fixture-api-key'),
    ])->all(),
]);
