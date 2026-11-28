<?php

namespace App\Services;

use App\Services\Kpi\ResultAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Monetary attribution is independent of fractional KPI sale credits. */
class EmployeeReportIncome
{
    public const KEYS = ['company_income', 'agent_income'];

    public static function empty(): array
    {
        return ['company_income' => 0, 'agent_income' => 0,
            'income_missing' => ['company_income' => 0, 'agent_income' => 0]];
    }

    public function build(Request $request, Collection $properties, PropertyReportQuery $query): Collection
    {
        $rows = [];
        $add = function (int $employee, string $key, ?float $amount) use (&$rows) {
            $rows[$employee] ??= self::empty();
            if ($amount === null) {
                $rows[$employee]['income_missing'][$key]++;
            } else {
                $rows[$employee][$key] = round($rows[$employee][$key] + $amount, 2);
            }
        };
        $closed = $properties->whereIn('moderation_status', ['sold', 'rented']);
        foreach ($closed as $property) {
            $add($query->ownerId($property), 'company_income', ResultAmount::convert(
                $property->company_commission_amount, $property->company_commission_currency,
                'TJS', $property->deal_fx_rate));
        }

        if (Schema::hasTable('property_agent_sales')) {
            // Scope by the recipient, so a helper's commission survives an
            // employee/group filter without including another agent's income.
            $seller = app(SalesAttributionService::class)->primarySellerExpression();
            $columns = Schema::getColumnListing('property_agent_sales');
            $select = [DB::raw('COALESCE(income_participant.agent_id, '.$seller.') as income_employee_id')];
            foreach (['agent_commission_amount', 'agent_commission_currency'] as $column) {
                $select[] = in_array($column, $columns, true)
                    ? 'income_participant.'.$column : DB::raw('NULL as '.$column);
            }
            $select[] = Schema::hasColumn('properties', 'deal_fx_rate')
                ? 'properties.deal_fx_rate' : DB::raw('NULL as deal_fx_rate');
            $participants = $query->inventory($request, participantIncome: true)
                ->whereIn('properties.moderation_status', ['sold', 'rented'])->toBase()->get($select);
            foreach ($participants as $participant) {
                $add((int) $participant->income_employee_id, 'agent_income', ResultAmount::convert(
                    $participant->agent_commission_amount, $participant->agent_commission_currency,
                    'TJS', $participant->deal_fx_rate));
            }
        } else {
            foreach ($closed as $property) {
                $add($query->ownerId($property), 'agent_income', null);
            }
        }

        return collect($rows);
    }
}
