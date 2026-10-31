<?php

namespace App\Modules\Academics\Enums;

/**
 * draft → submitted → approved (in use) → archived. A submitted version can be sent back to draft.
 */
enum CurriculumStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Archived = 'archived';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
