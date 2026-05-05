<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Models\User;
use App\Services\DailyReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillKpiSalesCreditsCommand extends Command
{
    protected $signature = 'kpi:backfill-sales 
        {date_from : Start date (Y-m-d)} 
        {date_to : End date (Y-m-d)}';

    protected $description = 'Backfill daily report sales_count/deals_count from sold_at with sale participant attribution.';

    public function __construct(private readonly DailyReportService $dailyReportService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!Schema::hasTable('daily_reports') || !Schema::hasTable('users')) {
            $this->error('Required tables are missing.');

            return self::FAILURE;
        }

        $from = Carbon::parse((string) $this->argument('date_from'), 'Asia/Dushanbe')->startOfDay();
        $to = Carbon::parse((string) $this->argument('date_to'), 'Asia/Dushanbe')->endOfDay();
        if ($from->greaterThan($to)) {
            $this->error('date_from must be <= date_to');

            return self::FAILURE;
        }

        $users = User::query()->select(['id', 'role_id', 'branch_id', 'branch_group_id'])->with('role')->get();
        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $rowsUpdated = 0;
        DB::transaction(function () use ($users, $days, &$rowsUpdated): void {
            foreach ($days as $day) {
                foreach ($users as $user) {
                    $auto = $this->dailyReportService->autoMetrics($user, $day);

                    $attributes = [
                        'deals_count' => (int) floor((float) ($auto['sales_count'] ?? $auto['deals_count'] ?? 0)),
                    ];

                    if (Schema::hasColumn('daily_reports', 'sales_count')) {
                        $attributes['sales_count'] = (float) ($auto['sales_count'] ?? 0);
                    }

                    $report = DailyReport::query()->where('user_id', $user->id)->whereDate('report_date', $day)->first();
                    if (!$report) {
                        continue;
                    }

                    $report->update($attributes);
                    $rowsUpdated++;
                }
            }
        });

        $this->info(sprintf('Backfill complete. Updated rows: %d', $rowsUpdated));

        return self::SUCCESS;
    }
}

