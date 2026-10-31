<?php

namespace App\Modules\Students\Authorization;

use App\Shared\Authorization\PermissionProvider;

class StudentsPermissions implements PermissionProvider
{
    public const RecordsView = 'students.records.view';

    public const RecordsCreate = 'students.records.create';

    public const RecordsUpdate = 'students.records.update';

    public const RecordsImport = 'students.records.import';

    public const StatusChange = 'students.status.change';

    public const DocumentsVerify = 'students.documents.verify';

    public const ChangesApprove = 'students.changes.approve';

    public static function module(): string
    {
        return 'students';
    }

    public static function permissions(): array
    {
        return [
            self::RecordsView => ['description' => 'View student records (heads of department and faculty see their departments only)', 'sensitive' => true],
            self::RecordsCreate => ['description' => 'Add students'],
            self::RecordsUpdate => ['description' => 'Edit student records, guardians and section placement', 'sensitive' => true],
            self::RecordsImport => ['description' => 'Bulk import existing students from CSV'],
            self::StatusChange => ['description' => 'Change enrollment status (leave, detained, graduated, dropped out…)', 'sensitive' => true],
            self::DocumentsVerify => ['description' => 'Verify or reject student documents'],
            self::ChangesApprove => ['description' => 'Approve or reject profile correction requests'],
        ];
    }
}
