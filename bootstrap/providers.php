<?php

use App\Modules\Academics\AcademicsServiceProvider;
use App\Modules\Admissions\AdmissionsServiceProvider;
use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Communication\CommunicationServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
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
];
