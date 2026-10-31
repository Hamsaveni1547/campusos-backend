<?php

use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Admissions\Authorization\AdmissionsPermissions;
use App\Modules\Communication\Authorization\CommunicationPermissions;
use App\Modules\HR\Authorization\HRPermissions;
use App\Modules\HR\Imports\EmployeeImporter;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Imports\UserImporter;
use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Students\Authorization\StudentsPermissions;
use App\Modules\Students\Imports\StudentImporter;
use App\Modules\SystemAdmin\Authorization\SystemAdminPermissions;
use App\Modules\Timetable\Authorization\TimetablePermissions;

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | Base URL of the CampusOS frontend application. Used to build links in
    | emails (password reset, account setup, email verification).
    |
    */

    'frontend_url' => env('CAMPUSOS_FRONTEND_URL', 'http://localhost:8001'),

    /*
    | IP addresses of CampusOS frontend servers. Requests from these may pass the
    | real browser IP and user agent in X-Client-IP / X-Client-User-Agent so that
    | sign-in history, rate limits and audit logs show the actual user.
    */
    'trusted_frontends' => env('CAMPUSOS_TRUSTED_FRONTENDS', '127.0.0.1,::1'),

    'frontend_paths' => [
        'reset_password' => '/reset-password',
        'setup_account' => '/setup-account',
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-factor authentication
    |--------------------------------------------------------------------------
    */

    'mfa' => [
        'issuer' => env('CAMPUSOS_MFA_ISSUER', env('APP_NAME', 'CampusOS')),
        'challenge_ttl_minutes' => 10,
        'challenge_max_attempts' => 5,
        'recovery_codes_count' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | Private disk used for all uploads and the list of upload purposes. Each
    | purpose defines its classification and allowed MIME types. Size limits
    | are configured per institution in the "files" settings group.
    |
    */

    'files' => [
        'disk' => env('CAMPUSOS_FILES_DISK', 'local'),
        'download_url_ttl_minutes' => 5,
        'purposes' => [
            'avatar' => [
                'classification' => 'internal',
                'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            ],
            'institution_logo' => [
                'classification' => 'public',
                'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'],
            ],
            'document' => [
                'classification' => 'confidential',
                'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
            ],
            'attachment' => [
                'classification' => 'internal',
                'mimes' => [
                    'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/plain', 'text/csv',
                ],
            ],
            'import' => [
                'classification' => 'confidential',
                'mimes' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module service providers
    |--------------------------------------------------------------------------
    |
    | Permission providers contribute permission definitions to the registry.
    | Importers are keyed by import type.
    |
    */

    'permission_providers' => [
        IdentityPermissions::class,
        InstitutionPermissions::class,
        CommunicationPermissions::class,
        SystemAdminPermissions::class,
        HRPermissions::class,
        AdmissionsPermissions::class,
        StudentsPermissions::class,
        AcademicsPermissions::class,
        TimetablePermissions::class,
    ],

    /*
    | Course categories used in a curriculum (value => label).
    */
    'academics' => [
        'course_categories' => [
            'core' => 'Professional core',
            'basic_science' => 'Basic science',
            'engineering_science' => 'Engineering science',
            'humanities' => 'Humanities & management',
            'professional_elective' => 'Professional elective',
            'open_elective' => 'Open elective',
            'lab' => 'Laboratory',
            'project' => 'Project / internship',
            'mandatory' => 'Mandatory (non-credit)',
        ],
    ],

    /*
    | Reservation categories an applicant can claim (value => label).
    */
    'admissions' => [
        'categories' => [
            'GM' => 'General merit',
            'SC' => 'Scheduled Caste',
            'ST' => 'Scheduled Tribe',
            'CAT1' => 'Category 1',
            '2A' => 'Category 2A',
            '2B' => 'Category 2B',
            '3A' => 'Category 3A',
            '3B' => 'Category 3B',
            'EWS' => 'Economically weaker section',
        ],
    ],

    'importers' => [
        'users' => UserImporter::class,
        'employees' => EmployeeImporter::class,
        'students' => StudentImporter::class,
    ],

];
