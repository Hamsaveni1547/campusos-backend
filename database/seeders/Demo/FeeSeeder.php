<?php

namespace Database\Seeders\Demo;

use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Models\FeeHead;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use Database\Seeders\Support\CampusSeeder;

/**
 * Fee heads, an Odd 2026 fee structure per programme raised for its students, and payments:
 * most paid in full, some part-paid, some not at all (overdue), one receipt cancelled, and a
 * concession waiting for the principal.
 */
class FeeSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (FeeHead::query()->exists()) {
            return;
        }

        $finance = app(FinanceService::class);
        $accounts = $this->user('accounts@'.self::DOMAIN);
        $term = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();

        $this->as($accounts, function () use ($finance, $accounts, $term): void {
            $heads = collect([['TUI', 'Tuition fee'], ['DEV', 'Development fee'], ['EXM', 'Examination fee'], ['LIB', 'Library fee'], ['FIN', 'Fine']])
                ->mapWithKeys(fn (array $h): array => [$h[0] => FeeHead::query()->create(['code' => $h[0], 'name' => $h[1]])->id]);

            foreach (['BE-CS' => 52000, 'BE-AIDS' => 52000, 'BE-IT' => 48000, 'MTECH-CSE' => 45000, 'MCA' => 40000, 'MBA' => 55000] as $code => $tuition) {
                $structure = $finance->saveStructure(
                    ['name' => $code.' Odd semester 2026', 'programme_id' => Programme::query()->where('code', $code)->value('id'), 'due_on' => '2026-09-15'],
                    [['fee_head_id' => $heads['TUI'], 'amount' => $tuition], ['fee_head_id' => $heads['DEV'], 'amount' => 7500], ['fee_head_id' => $heads['EXM'], 'amount' => 2400], ['fee_head_id' => $heads['LIB'], 'amount' => 1500]],
                );
                $finance->generate($structure, $term);
            }

            foreach (FeeDemand::query()->with('student')->orderBy('id')->get() as $i => $demand) {
                $mode = ['cash', 'upi', 'neft', 'dd', 'card'][$i % 5];
                $reference = $mode === 'cash' ? null : strtoupper($mode).'-'.(500000 + $i * 37);
                $date = now()->subDays(5 + $i % 40)->toDateString();

                match (true) {
                    $i % 7 === 3 => null, // not paid: overdue
                    $i % 5 === 2 => $finance->collect($demand->student, round((float) $demand->amount / 2, 2), $mode, $reference, $date),
                    default => $finance->collect($demand->student, (float) $demand->amount, $mode, $reference, $date),
                };
            }

            $student = Student::query()->where('user_id', $this->user('student@'.self::DOMAIN)->id)->firstOrFail();
            $finance->raise($student, 'Library fine — late return', now()->addWeek()->toDateString(), [['fee_head_id' => $heads['FIN'], 'amount' => 120]]);

            $bounced = $finance->collect(Student::query()->whereKey(FeeDemand::query()->where('status', 'partly_paid')->value('student_id'))->firstOrFail(), 1000, 'cheque', 'CHQ-104455', now()->subDays(3)->toDateString());
            $finance->cancelPayment($bounced, 'Cheque bounced', $this->fresh($accounts));

            $unpaid = FeeDemand::query()->where('status', 'open')->whereNotNull('fee_structure_id')->orderBy('id')->firstOrFail();
            $finance->requestConcession($unpaid, 10000, 'Merit scholarship (top 5% in the previous semester)');
        });
    }
}
