<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OpenAITest extends Command
{
    protected $signature = 'openai:test';

    protected $description = 'Ping OpenAI API and print response';

    public function handle(): int
    {
        $request = Http::timeout(30);
        $relayKey = config('services.openai.relay_shared_key');
        $apiKey = config('services.openai.api_key');

        if (! empty($relayKey)) {
            $request = $request->withHeaders([
                'X-Relay-Key' => $relayKey,
            ]);
        } elseif (! empty($apiKey)) {
            $request = $request->withToken($apiKey);
        } else {
            $this->error('OpenAI credentials are not configured.');

            return self::FAILURE;
        }

        $resp = $request->post(rtrim(config('services.openai.base_url', 'https://api.openai.com'), '/').'/v1/responses', [
            'model' => config('services.openai.model', 'gpt-5-mini'),
            'input' => 'Say "pong" if you can read this.',
            'store' => false,
        ]);

        if ($resp->failed()) {
            $this->error('OpenAI request failed (HTTP '.$resp->status().').');

            return self::FAILURE;
        }

        $this->info(json_encode($resp->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
