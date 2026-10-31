<?php

namespace App\Modules\QuestionBank\Authorization;

use App\Shared\Authorization\PermissionProvider;

class QuestionBankPermissions implements PermissionProvider
{
    public const QuestionsContribute = 'qbank.questions.contribute';

    public const QuestionsManage = 'qbank.questions.manage';

    public const PapersSet = 'qbank.papers.set';

    public const PapersReview = 'qbank.papers.review';

    public const PapersSeal = 'qbank.papers.seal';

    public const PapersRelease = 'qbank.papers.release';

    public const LogsView = 'qbank.logs.view';

    public static function module(): string
    {
        return 'qbank';
    }

    public static function permissions(): array
    {
        return [
            self::QuestionsContribute => ['description' => 'Add questions to the bank and edit your own'],
            self::QuestionsManage => ['description' => 'Edit or retire any question in the bank'],
            self::PapersSet => ['description' => 'Create blueprints, generate question papers and submit them for review'],
            self::PapersReview => ['description' => 'Approve or return question papers (not your own)', 'sensitive' => true],
            self::PapersSeal => ['description' => 'Seal approved question papers', 'sensitive' => true],
            self::PapersRelease => ['description' => 'Open sealed question papers in the release window before the exam', 'sensitive' => true],
            self::LogsView => ['description' => 'View who opened question papers', 'sensitive' => true],
        ];
    }
}
