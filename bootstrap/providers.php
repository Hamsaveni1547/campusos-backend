<?php

use App\Modules\Admissions\AdmissionsServiceProvider;
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
];
