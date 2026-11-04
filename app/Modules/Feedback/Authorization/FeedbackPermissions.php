<?php

namespace App\Modules\Feedback\Authorization;

use App\Shared\Authorization\PermissionProvider;

class FeedbackPermissions implements PermissionProvider
{
    public const SurveysManage = 'feedback.surveys.manage';

    public const ResultsView = 'feedback.results.view';

    public const ComplaintsHandle = 'feedback.complaints.handle';

    public const ComplaintsRestricted = 'feedback.complaints.restricted';

    public static function module(): string
    {
        return 'feedback';
    }

    public static function permissions(): array
    {
        return [
            self::SurveysManage => ['description' => 'Create, open and close feedback surveys'],
            self::ResultsView => ['description' => 'See feedback results of every class and survey'],
            self::ComplaintsHandle => ['description' => 'Handle complaints: assign, reply, resolve'],
            self::ComplaintsRestricted => ['description' => 'Handle restricted complaints (e.g. harassment, ragging)', 'sensitive' => true],
        ];
    }
}
