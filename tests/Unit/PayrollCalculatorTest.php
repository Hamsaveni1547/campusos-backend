<?php

namespace Tests\Unit;

use App\Modules\Payroll\Support\PayrollCalculator;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    /**
     * Basic 50,000; DA 50% of basic; HRA 24% of basic; conveyance 1,600 fixed;
     * PF 12% of basic capped at 1,800 (not prorated); professional tax 200.
     *
     * @return list<array{code: string, name: string, kind: string, calculation: string, percent: float|null, max_amount: float|null, affected_by_lop: bool, amount: float|null}>
     */
    private function salary(): array
    {
        return [
            ['code' => 'BASIC', 'name' => 'Basic', 'kind' => 'earning', 'calculation' => 'fixed', 'percent' => null, 'max_amount' => null, 'affected_by_lop' => true, 'amount' => 50000.0],
            ['code' => 'DA', 'name' => 'Dearness allowance', 'kind' => 'earning', 'calculation' => 'percent', 'percent' => 50.0, 'max_amount' => null, 'affected_by_lop' => true, 'amount' => null],
            ['code' => 'HRA', 'name' => 'House rent allowance', 'kind' => 'earning', 'calculation' => 'percent', 'percent' => 24.0, 'max_amount' => null, 'affected_by_lop' => true, 'amount' => null],
            ['code' => 'CONV', 'name' => 'Conveyance', 'kind' => 'earning', 'calculation' => 'fixed', 'percent' => null, 'max_amount' => null, 'affected_by_lop' => true, 'amount' => 1600.0],
            ['code' => 'PF', 'name' => 'Provident fund', 'kind' => 'deduction', 'calculation' => 'percent', 'percent' => 12.0, 'max_amount' => 1800.0, 'affected_by_lop' => false, 'amount' => null],
            ['code' => 'PT', 'name' => 'Professional tax', 'kind' => 'deduction', 'calculation' => 'fixed', 'percent' => null, 'max_amount' => null, 'affected_by_lop' => false, 'amount' => 200.0],
        ];
    }

    public function test_full_month(): void
    {
        $slip = PayrollCalculator::compute($this->salary(), 30, 0);

        // 50,000 + 25,000 + 12,000 + 1,600 = 88,600; PF capped 1,800 + PT 200
        $this->assertSame([88600.0, 2000.0, 86600.0], [$slip['gross'], $slip['deductions'], $slip['net']]);
    }

    public function test_loss_of_pay_prorates_earnings_and_adjustments_are_added(): void
    {
        $slip = PayrollCalculator::compute($this->salary(), 30, 3, [['kind' => 'earning', 'label' => 'Exam duty honorarium', 'amount' => 1500.0], ['kind' => 'deduction', 'label' => 'Salary advance recovery', 'amount' => 5000.0]]);

        // 27/30: basic 45,000; DA 22,500; HRA 10,800; conveyance 1,440 → 79,740 + 1,500
        $this->assertSame(81240.0, $slip['gross']);
        $this->assertSame(7000.0, $slip['deductions']);
        $this->assertSame('Exam duty honorarium', $slip['lines'][6]['name']);
    }
}
