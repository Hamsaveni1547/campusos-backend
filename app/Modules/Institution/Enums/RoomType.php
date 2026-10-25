<?php

namespace App\Modules\Institution\Enums;

enum RoomType: string
{
    case Classroom = 'classroom';
    case Laboratory = 'laboratory';
    case SeminarHall = 'seminar_hall';
    case ExamHall = 'exam_hall';
    case Library = 'library';
    case StaffRoom = 'staff_room';
    case Office = 'office';
    case Other = 'other';
}
