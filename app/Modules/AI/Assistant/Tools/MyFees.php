<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Services\FinanceService;

class MyFees extends StudentTool
{
    public function __construct(private FinanceService $finance) {}

    public function name(): string
    {
        return 'my_fees';
    }

    public function description(): string
    {
        return "The student's fee balance, overdue amount and each unpaid fee with its due date (amounts in Indian rupees).";
    }

    public function run(User $user, array $arguments): array
    {
        $student = $this->student($user);

        if ($student === null) {
            return [];
        }

        $totals = $this->finance->totals($student);

        return [
            'balance' => $totals['balance'],
            'overdue' => $totals['overdue'],
            'paid_so_far' => $totals['paid'],
            'unpaid' => $this->finance->outstanding($student)->map(fn (FeeDemand $d): array => ['fee' => $d->title, 'due_on' => $d->due_on->toDateString(), 'balance' => $d->balance()])->values()->all(),
            'how_to_pay' => 'My fees page, then Pay online; or the accounts counter.',
        ];
    }
}
