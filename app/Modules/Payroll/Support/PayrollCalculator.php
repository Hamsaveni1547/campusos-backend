<?php

namespace App\Modules\Payroll\Support;

/**
 * Works out one payslip. Pure and deterministic.
 *
 * Components are fixed amounts or a percentage of BASIC (optionally capped). Loss-of-pay days reduce
 * every component marked `affected_by_lop` by (days − LOP) ÷ days. One-off adjustments are added as
 * extra earnings or deductions. Amounts are rounded to whole rupees, as payslips usually are.
 */
final class PayrollCalculator
{
    /**
     * @param  list<array{code: string, name: string, kind: string, calculation: string, percent: float|null, max_amount: float|null, affected_by_lop: bool, amount: float|null}>  $components  in display order; amount is the employee's fixed amount
     * @param  list<array{kind: string, label: string, amount: float}>  $adjustments
     * @return array{lines: list<array{code: string|null, name: string, kind: string, amount: float}>, gross: float, deductions: float, net: float}
     */
    public static function compute(array $components, int $daysInMonth, float $lopDays, array $adjustments = []): array
    {
        $factor = $daysInMonth > 0 ? max(0.0, ($daysInMonth - $lopDays) / $daysInMonth) : 1.0;
        $basic = 0.0;

        foreach ($components as $component) {
            if ($component['code'] === 'BASIC') {
                $basic = (float) ($component['amount'] ?? 0) * ($component['affected_by_lop'] ? $factor : 1.0);
            }
        }

        $lines = [];

        foreach ($components as $component) {
            if ($component['calculation'] === 'percent') {
                $amount = $basic * (float) $component['percent'] / 100;
            } else {
                $amount = (float) ($component['amount'] ?? 0) * ($component['affected_by_lop'] && $component['code'] !== 'BASIC' ? $factor : 1.0);
            }

            if ($component['code'] === 'BASIC') {
                $amount = $basic;
            }

            if ($component['max_amount'] !== null) {
                $amount = min($amount, (float) $component['max_amount']);
            }

            $amount = round($amount);

            if ($amount > 0) {
                $lines[] = ['code' => $component['code'], 'name' => $component['name'], 'kind' => $component['kind'], 'amount' => $amount];
            }
        }

        foreach ($adjustments as $adjustment) {
            $lines[] = ['code' => null, 'name' => $adjustment['label'], 'kind' => $adjustment['kind'], 'amount' => round($adjustment['amount'])];
        }

        $gross = (float) array_sum(array_map(fn (array $l): float => $l['kind'] === 'earning' ? $l['amount'] : 0.0, $lines));
        $deductions = (float) array_sum(array_map(fn (array $l): float => $l['kind'] === 'deduction' ? $l['amount'] : 0.0, $lines));

        return ['lines' => $lines, 'gross' => $gross, 'deductions' => $deductions, 'net' => $gross - $deductions];
    }
}
