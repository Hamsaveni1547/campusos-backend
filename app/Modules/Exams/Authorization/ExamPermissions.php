<?php

namespace App\Modules\Exams\Authorization;

use App\Shared\Authorization\PermissionProvider;

class ExamPermissions implements PermissionProvider
{
    public const CyclesManage = 'exams.cycles.manage';

    public const EligibilityOverride = 'exams.eligibility.override';

    public const EvaluationEnter = 'exams.evaluation.enter';

    public const ScriptsDecode = 'exams.scripts.decode';

    public const ResultsProcess = 'exams.results.process';

    public const ResultsVerify = 'exams.results.verify';

    public const ResultsPublish = 'exams.results.publish';

    public const RevaluationManage = 'exams.revaluation.manage';

    public const ReportsView = 'exams.reports.view';

    public static function module(): string
    {
        return 'exams';
    }

    public static function permissions(): array
    {
        return [
            self::CyclesManage => ['description' => 'Create exam cycles, open and close registration, and set the exam timetable'],
            self::EligibilityOverride => ['description' => 'Make a student eligible for an exam despite shortages', 'sensitive' => true],
            self::EvaluationEnter => ['description' => 'Enter external marks against script codes'],
            self::ScriptsDecode => ['description' => 'See which student wrote which answer script', 'sensitive' => true],
            self::ResultsProcess => ['description' => 'Calculate grades, SGPA and CGPA'],
            self::ResultsVerify => ['description' => 'Verify processed results (not your own)', 'sensitive' => true],
            self::ResultsPublish => ['description' => 'Publish verified results to students', 'sensitive' => true],
            self::RevaluationManage => ['description' => 'Handle revaluation requests and revise marks', 'sensitive' => true],
            self::ReportsView => ['description' => 'View exam registrations, results and analysis'],
        ];
    }
}
