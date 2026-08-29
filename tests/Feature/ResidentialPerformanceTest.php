<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResidentialLoadFixture;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialPerformanceTest extends TestCase
{
    public static function auditModes(): array
    {
        return ['without audit' => [false], 'with real API audit' => [true]];
    }

    #[DataProvider('auditModes')]
    public function test_large_inventory_has_bounded_initial_detail_and_no_per_card_query_growth(bool $withAudit): void
    {
        ResidentialSchema::create();
        ResidentialLoadFixture::seed();
        if ($withAudit) {
            (require database_path('migrations/2026_06_03_120000_create_api_request_logs_table.php'))->up();
            config(['audit.api_requests.enabled' => true, 'audit.api_requests.log_methods' => ['GET'], 'audit.api_requests.excluded_paths' => []]);
        } else {
            $this->withoutMiddleware(LogApiRequest::class);
        }
        $this->assertDatabaseCount('new_buildings', 100);
        $this->assertDatabaseCount('developer_units', 10000);
        $counts = [];
        foreach ([1, 100] as $perPage) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/new-buildings?per_page='.$perPage)->assertOk()->assertJsonCount($perPage, 'data');
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[0], $counts[1], 'Query count must not grow with the number of catalog cards, including audit writes.');
        $filteredCounts = [];
        foreach ([1, 100] as $perPage) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $filtered = $this->getJson('/api/new-buildings?rooms[]=2&price_max=900000&area_min=50&per_page='.$perPage)
                ->assertOk()->assertJsonCount($perPage, 'data')->assertJsonPath('total', 100)
                ->assertJsonPath('meta.total_available_units', 894);
            $filteredCounts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            if ($perPage === 100) {
                $this->assertSame(894, array_sum(array_column($filtered->json('data'), 'available_count')));
            }
        }
        $this->assertSame($filteredCounts[0], $filteredCounts[1], 'Filtered cards must also have bounded query count.');
        $detail = $this->getJson('/api/new-buildings/1?inventory=paginated')->assertOk();
        $this->assertLessThanOrEqual(20, count($detail->json('data.units')));
        $this->assertLessThanOrEqual(200 * 1024, strlen($detail->getContent()));
        $this->getJson('/api/new-buildings/1/availability-grid?block_id=1&entrance_id=1')->assertOk()->assertJsonCount(1000, 'data.cells');
        if ($withAudit) {
            $this->assertDatabaseCount('api_request_logs', 6);
            $this->assertSame(4, DB::table('api_request_logs')->where('path', 'api/new-buildings')->count());
            foreach (DB::table('api_request_logs')->get() as $log) {
                $this->assertSame('GET', $log->method);
                $this->assertSame(200, $log->status_code);
                $this->assertNotNull($log->trace_id);
                $this->assertNotNull($log->duration_ms);
                $this->assertNull($log->request_query);
                $this->assertNull($log->request_body);
                $this->assertNull($log->error_message);
            }
        }
        fwrite(STDOUT, '\nLoad fixture: queries '.implode('/', $counts).', detail bytes '.strlen($detail->getContent())."\n");
    }
}
