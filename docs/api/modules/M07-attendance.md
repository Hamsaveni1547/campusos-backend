# M07 Attendance — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. `GET attendance/options` → `statuses[]` (present, absent, late, on_duty, medical), `counts_as_attended[]`, `threshold`, `condonation_min`, `edit_window_hours`.

## Rules (settings group `attendance`)

`threshold` (75), `condonation_min` (65), `edit_window_hours` (48 after the end of the class day; 0 = always open), `late_counts_present` (on), `on_duty_counts_present` (on), `medical_counts_present` (off).
Standing per student per course: `ok` (≥ threshold), `condoned`, `condonable` (between condonation_min and threshold), `shortage`, `no_classes`. Percentages are always calculated from the marks; they are never stored or edited.

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin | `attendance.*` |
| Head of department | mark (own classes), approve corrections, condone, reports |
| Admin staff | edit any session at any time, approve corrections, reports |
| Institution head | reports |
| Faculty | mark own classes (and classes they substitute that day) within the edit window |
| Student | own attendance, request corrections |

## Teachers

- `GET me/attendance/pending` → periods from the teacher's timetable in the last 7 days (cancelled / handed-over excluded, today only once started) not yet marked: `date, class_id, slot_id, course, section, starts_at…`.
- `GET attendance/sheet?class=&date=&slot=` → `students[]` with current `status` (null if not marked), `session` (marked_by, marked_at, open), `can_mark`.
- `POST attendance/sheet` `{class_id, date, time_slot_id?, component?, notes?, statuses{student_id: status}}` — students left out are present. First marking is allowed any day of the term up to today; changing an existing sheet after the window needs `attendance.records.edit` (`EDIT_WINDOW_CLOSED`).
- `GET attendance/classes/{class}/summary` → per student `held, attended, percentage, condoned, standing` (class teachers or report viewers).

## Monitoring

- `GET attendance/shortage?filter[section]=&filter[term]=&filter[standing]=shortage,condonable` → one row per student per course below the threshold.
- `POST attendance/condonations` `{student_id, class_id, reason, fee_reference?}` — only within the band (`NOT_CONDONABLE`).
- `GET attendance-corrections?filter[status]=pending` · `POST attendance-corrections/{id}/decide` `{approve, remarks}` (remarks needed to reject); approval changes the mark.

## Students

- `GET me/attendance` → courses this term with `held, attended, percentage, condoned, standing`.
- `GET me/attendance/{class}` → `records[]` (date, slot, status, latest correction).
- `POST attendance-corrections` `{session_id, requested_status, reason}` (a teacher of the class adds `student_id`). One pending request per mark.

## Later

Self check-in (QR/geo), biometric import, leave integration (M14), weekly shortage alerts to students/parents.

## Error codes

`DATE_INVALID`, `NOT_CLASS_TEACHER`, `EDIT_WINDOW_CLOSED`, `NO_STUDENTS`, `STUDENT_NOT_IN_CLASS`, `NOT_CONDONABLE`, `NO_CHANGE`, `CORRECTION_PENDING`, `ALREADY_REVIEWED`, `REMARKS_REQUIRED`, `NOT_A_STUDENT`.
