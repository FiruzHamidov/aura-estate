<?php

namespace App\Services\AgentWorkspace;

use Illuminate\Validation\ValidationException;

/** Exact decimal arithmetic. Monetary floats never enter the rating ledger. */
final class CommissionMath
{
    public static function decimal(string|int $value, int $scale, string $field): int
    {
        $text = (string) $value;
        if (! preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,'.$scale.'}))?$/D', $text, $matches)) {
            throw ValidationException::withMessages([$field => 'Укажите неотрицательное число, не более '.$scale.' знаков после точки.']);
        }

        return (int) ($matches[1].str_pad($matches[2] ?? '', $scale, '0'));
    }

    public static function base(string|int $fee, string|int $refund, string $currency, string|int|null $rate): int
    {
        $feeMinor = self::decimal($fee, 2, 'commission');
        $refundMinor = self::decimal($refund, 2, 'refund');
        if ($refundMinor > $feeMinor) {
            throw ValidationException::withMessages(['refund' => 'Коррекция не может превышать комиссию.']);
        }
        if ($currency === 'TJS') {
            return $feeMinor - $refundMinor;
        }
        $fx = self::decimal($rate ?? '', 6, 'fx_rate');
        if ($fx <= 0 || $fx > 1000000000) {
            throw ValidationException::withMessages(['fx_rate' => 'Курс TJS за 1 USD должен быть больше нуля и не более 1000.']);
        }
        // Split the multiplication to stay inside a signed 64-bit integer.
        $net = $feeMinor - $refundMinor;
        $whole = intdiv($fx, 1000000);
        $fraction = $fx % 1000000;

        return $net * $whole + intdiv($net, 1000000) * $fraction
            + intdiv(($net % 1000000) * $fraction + 500000, 1000000);
    }

    public static function allocate(int $base, array $participants): array
    {
        if (array_sum(array_column($participants, 'share_bps')) !== 10000) {
            throw ValidationException::withMessages(['participants' => 'Сумма долей должна быть ровно 100%.']);
        }
        $seen = [];
        foreach ($participants as $index => &$p) {
            $key = ($p['agent_id'] ?? 'external').':'.$p['side'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['participants' => 'Участник и сторона не должны повторяться.']);
            }
            $seen[$key] = true;
            $p['allocation_key'] = $key;
            $product = $base * $p['share_bps'];
            $p['credit_minor'] = intdiv($product, 10000);
            $p['_remainder'] = $product % 10000;
        }
        unset($p);
        $left = $base - array_sum(array_column($participants, 'credit_minor'));
        $order = array_keys($participants);
        usort($order, fn ($a, $b) => ($participants[$b]['_remainder'] <=> $participants[$a]['_remainder']) ?: strcmp($participants[$a]['allocation_key'], $participants[$b]['allocation_key']));
        foreach (array_slice($order, 0, $left) as $index) {
            $participants[$index]['credit_minor']++;
        }
        foreach ($participants as &$p) {
            unset($p['_remainder']);
        }

        return $participants;
    }

    public static function rank(array $rows): array
    {
        usort($rows, fn ($a, $b) => ($b['score_minor'] <=> $a['score_minor']) ?: ($b['deal_count'] <=> $a['deal_count']) ?: ($a['agent_id'] <=> $b['agent_id']));
        $previous = null;
        $rank = null;
        $counts = array_count_values(array_column($rows, 'score_minor'));
        foreach ($rows as $index => &$row) {
            if ($row['score_minor'] !== $previous) {
                $rank = $index + 1;
            }
            $previous = $row['score_minor'];
            $row['rank'] = $row['score_minor'] > 0 ? $rank : null;
            $row['shared_rank'] = $row['rank'] !== null && $counts[$row['score_minor']] > 1;
        }

        return $rows;
    }
}
