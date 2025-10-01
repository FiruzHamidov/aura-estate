<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OpenAITest extends Command
{
    protected $signature = 'openai:test';
    protected $description = 'Ping OpenAI API and print response';

    public function handle(): void
    {
        $resp = Http::withToken(env('OPENAI_API_KEY'))
            ->post(env('OPENAI_BASE').'/v1/responses', [
                'model' => env('OPENAI_MODEL', 'o3-mini'),
                'input' => 'Say "pong" if you can read this.',
            ])
            ->json();

        $this->info(json_encode($resp, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }
}
