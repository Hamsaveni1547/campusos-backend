<?php

use App\Modules\Academics\AcademicsServiceProvider;
use App\Modules\Admissions\AdmissionsServiceProvider;
use App\Modules\AI\AIServiceProvider;
use App\Modules\Assessment\AssessmentServiceProvider;
use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Communication\CommunicationServiceProvider;
use App\Modules\Exams\ExamsServiceProvider;
use App\Modules\Feedback\FeedbackServiceProvider;
use App\Modules\Finance\FinanceServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Library\LibraryServiceProvider;
use App\Modules\QuestionBank\QuestionBankServiceProvider;
use App\Providers\AppServiceProvider;
use App\Shared\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    CommunicationServiceProvider::class,
    IdentityServiceProvider::class,
    AdmissionsServiceProvider::class,
    AcademicsServiceProvider::class,
    AttendanceServiceProvider::class,
    AssessmentServiceProvider::class,
    ExamsServiceProvider::class,
    QuestionBankServiceProvider::class,
    FinanceServiceProvider::class,
    LibraryServiceProvider::class,
    FeedbackServiceProvider::class,
    AIServiceProvider::class,
];
