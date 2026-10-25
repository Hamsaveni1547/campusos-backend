<?php

use App\Modules\Communication\Authorization\CommunicationPermissions;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Imports\UserImporter;
use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\SystemAdmin\Authorization\SystemAdminPermissions;

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
    | SMS
    |--------------------------------------------------------------------------
    |
    | Driver used by the SMS notification channel. "log" writes messages to the
    | application log and is meant for local development.
    |
    */

    'sms' => [
        'driver' => env('CAMPUSOS_SMS_DRIVER', 'log'),
        'sender_id' => env('CAMPUSOS_SMS_SENDER_ID', 'CMPSOS'),
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
    ],

    'importers' => [
        'users' => UserImporter::class,
    ],

];
