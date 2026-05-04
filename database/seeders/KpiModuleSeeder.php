<?php

namespace Database\Seeders;

use App\Models\KpiAcceptanceRun;
use App\Models\KpiEarlyRiskAlert;
use App\Models\KpiIntegrationStatus;
use App\Models\KpiQualityIssue;
use App\Services\KpiModuleService;
use Illuminate\Database\Seeder;

class KpiModuleSeeder extends Seeder
{
    public function run(): void
    {
        KpiIntegrationStatus::query()->updateOrCreate(['code' => 'crm_tasks'], ['name' => 'CRM Tasks', 'status' => 'ok', 'last_checked_at' => now()]);
        KpiIntegrationStatus::query()->updateOrCreate(['code' => 'daily_reports'], ['name' => 'Daily Reports', 'status' => 'ok', 'last_checked_at' => now()]);

        app(KpiModuleService::class)->telegramConfig();

        KpiQualityIssue::query()->firstOrCreate(['title' => 'Missing KPI source mapping'], [
            'severity' => 'low',
            'status' => 'open',
            'detected_at' => now(),
            'details' => ['source' => 'seed'],
        ]);

        KpiEarlyRiskAlert::query()->firstOrCreate(['alert_date' => now()->toDateString(), 'message' => 'Seed alert'], [
            'status' => 'acknowledged',
            'meta' => ['seed' => true],
        ]);

        KpiAcceptanceRun::query()->firstOrCreate(['run_type' => 'daily', 'status' => 'success'], [
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'details' => ['seed' => true],
        ]);
    }
}
