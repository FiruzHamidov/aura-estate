<?php

namespace App\Console\Commands;

use App\Services\Kpi\MotivationRecalculation;
use Illuminate\Console\Command;

final class RefreshMotivationCommand extends Command
{
    protected $signature = 'motivation:refresh';

    protected $description = 'Record earned rewards and flag changed eligibility without approving or issuing rewards';

    public function handle(MotivationRecalculation $service): int
    {
        $preview = $service->run(['preview' => true], null);
        if ($preview['changes'] === []) {
            return self::SUCCESS;
        }
        $result = $service->run(['preview_token' => $preview['preview_token'], 'reason' => 'Автоматическая проверка результата'], null);
        $this->info('Recorded '.count($result['changes']).' motivation changes.');

        return self::SUCCESS;
    }
}
