<?php

namespace App\Services\Kpi;

/** Shared by reports, the sticky banner and motivation eligibility. */
final class ResultProgress
{
    public static function calculate(?array $goal, int $count, float $amount, int $missingAmounts): array
    {
        $amount = round($amount, 2);
        $countTarget = $goal['count_target'] ?? null;
        $amountTarget = $goal['amount_target'] ?? null;
        $mode = $goal['mode'] ?? null;
        $countPct = $countTarget > 0 ? round(100 * $count / $countTarget, 1) : null;
        $amountPct = $amountTarget > 0 ? round(100 * $amount / $amountTarget, 1) : null;
        if ($countPct !== null && $count < $countTarget) {
            $countPct = min(99.9, $countPct);
        }
        if ($amountPct !== null && $amount < $amountTarget) {
            $amountPct = min(99.9, $amountPct);
        }
        $progress = match ($mode) {
            'count' => $countPct,
            'amount' => $amountPct,
            'count_or_amount' => max($countPct ?? 0, $amountPct ?? 0),
            default => null,
        };
        $achieved = $progress !== null && $progress >= 100;
        // A known qualifying OR branch is sufficient even with missing amounts.
        $incomplete = $missingAmounts > 0 && $mode !== 'count' && ! $achieved;

        return [
            'count' => $count, 'amount' => round($amount, 2),
            'missing_amounts' => $missingAmounts, 'amount_complete' => $missingAmounts === 0,
            'count_percent' => $countPct, 'amount_percent' => $amountPct,
            'progress_percent' => $progress, 'achieved' => $achieved,
            'progress_is_lower_bound' => $incomplete,
            'status' => $goal === null ? 'no_plan' : ($achieved ? 'achieved' : ($incomplete ? 'incomplete' : 'in_progress')),
            'remaining_count' => $countTarget ? max(0, $countTarget - $count) : null,
            'remaining_amount' => $amountTarget ? round(max(0, $amountTarget - $amount), 2) : null,
        ];
    }
}
