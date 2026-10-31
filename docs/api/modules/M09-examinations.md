# M09 Examinations — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. `GET exams/options` → `types[]` (regular, supplementary), `statuses[]`, `external_pass_percent`, `overall_pass_percent`, `grades[] {grade, min, points}`.

## Rules (settings group `exams`)

`external_pass_percent` (35), `overall_pass_percent` (40), `grades` (O 90/10, A+ 80/9, A 70/8, B+ 60/7, B 50/6, C 45/5, P 40/4).
Per course: total % = (internal + exam) ÷ (internal out of + exam max) × 100. Pass needs both pass marks; the grade is the highest band reached; otherwise `F`. Absent = `AB`, not eligible = `NE` (0 points). SGPA is credit-weighted over the cycle's courses; CGPA over the latest attempt of every course in published cycles. Backlogs = courses whose latest attempt failed.

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin, Examiner | `exams.*` |
| Institution head | reports, verify, publish |
| Admin staff | manage cycles and timetable, reports |
| Head of department | reports |
| Faculty | enter marks against script codes |
| Student | own exams, hall tickets, results, revaluation |

## Cycle flow

`draft → registration_open → registration_closed → results_processed → results_verified → published`

- `GET|POST exam-cycles` `{name, type, term_id, registration_ends_on?}`, `GET|PATCH exam-cycles/{id}` — show adds `eligibility` counts, `schedules[]` and `can {manage, process, verify, publish, enter_marks}`.
- `POST exam-cycles/{id}/open-registration` — a regular cycle registers every student for the courses of their term registration.
- `POST …/close-registration` — decides eligibility (attendance not in shortage or the condonable band, and verified or locked internal marks) and gives eligible scripts 8-digit codes.
- `POST …/eligibility` — recompute (e.g. after internal marks are verified). `POST exam-registrations/{id}/override` `{reason}` makes a student eligible.
- `POST …/process` (`MARKS_MISSING` if an eligible script has no marks or its course has no exam), `POST …/verify` (not by the processor: `SAME_PERSON`), `POST …/publish`.
- `GET exam-cycles/{id}/registrations?filter[eligibility]=&filter[course]=&filter[search]=` — `script_code` is shown only with `exams.scripts.decode`.
- `GET exam-cycles/{id}/results` → `summary {students, all_clear, pass_percent, grades}`, `courses[]`, `students[] {sgpa, cgpa, credits, backlogs, grades[]}`.

## Timetable and marks

- `POST exam-cycles/{id}/schedules` `{course_id, exam_date, starts_at, ends_at, max_marks}`, `PATCH|DELETE exam-schedules/{id}`; overlapping exams for the same student → `SCHEDULE_CLASH` (with `students`).
- `GET exam-schedules/{id}/scripts` → `scripts[] {code, attendance, marks}` (no names); `PUT exam-schedules/{id}/marks` `{marks{code: {attendance, marks?}}}`.

## Revaluation

- `GET revaluation-requests?filter[status]=`; `POST revaluation-requests/{id}/decide` `{new_marks}` (regrades, bumps `revision`, recalculates SGPA/CGPA) or `{remarks}` to reject.

## Students

- `GET me/exams` → cycles in progress with own `courses[] {eligibility, reasons, exam}`, `hall_ticket`, and `backlogs_open[]` while registration is open.
- `POST me/exam-cycles/{id}/backlogs` `{course_ids[]}` (`NOT_A_BACKLOG`, `REGISTRATION_CLOSED`).
- `GET me/hall-ticket/{cycle}` → student, photo id and eligible exams (404 until registration closes or if not eligible).
- `GET me/results` → `cgpa`, `backlogs`, published `results[]` with courses, grades and revaluation status.
- `POST me/revaluation-requests` `{registration_id, reason}` — exams written, after publishing, once per course.
